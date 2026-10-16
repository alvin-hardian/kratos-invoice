<?php
declare(strict_types=1);
namespace App\Services;

use App\Support\Format;
use App\Support\Repo;
use PDO;

final class DocService
{
    /** Whether the connected DB has invoices.show_signature (old DBs may be unmigrated). */
    private static function invoiceHasSignature(PDO $db): bool
    {
        static $cache = [];
        $key = spl_object_id($db);
        if (!array_key_exists($key, $cache)) {
            try {
                $cache[$key] = (bool)$db->query("SHOW COLUMNS FROM invoices LIKE 'show_signature'")->fetch();
            } catch (\Throwable) {
                $cache[$key] = false;
            }
        }
        return $cache[$key];
    }

    /** Whether the invoice has any non-voided payments recorded. */
    public static function hasActivePayments(PDO $db, int $invoiceId): bool
    {
        $st = $db->prepare('SELECT COUNT(*) c FROM payments WHERE invoice_id=? AND voided_at IS NULL');
        $st->execute([$invoiceId]);
        return ((int)$st->fetch()['c']) > 0;
    }

    public static function normalizePayload(array $post, PDO $db): array
    {
        $taxMap = Repo::taxMap($db);
        $sections = [];
        $rawSecs = $post['sections'] ?? [];
        if (!is_array($rawSecs)) $rawSecs = [];
        foreach (array_values($rawSecs) as $sec) {
            $lines = [];
            foreach (array_values($sec['lines'] ?? []) as $l) {
                $taxId = ($l['tax_rate_id'] ?? '') === '' ? null : (int)$l['tax_rate_id'];
                $rate = 0.0;
                if ($taxId && isset($taxMap[$taxId])) $rate = (float)$taxMap[$taxId]['rate'];
                elseif (isset($l['tax_rate']) && $l['tax_rate'] !== '') $rate = min(100, max(0, (float)$l['tax_rate']));
                $waived = !empty($l['waived']) ? 1 : 0;
                $priceMode = $l['price_mode'] ?? 'normal';
                if (!$waived) $priceMode = 'normal';
                elseif (!in_array($priceMode, ['waived','compliment','included'], true)) $priceMode = 'waived';
                $lines[] = [
                    'product_id' => ($l['product_id'] ?? '') === '' ? null : (int)$l['product_id'],
                    'description' => trim((string)($l['description'] ?? '')),
                    'quantity' => Format::parseQty($l['quantity'] ?? 1),
                    'unit_label' => trim((string)($l['unit_label'] ?? 'pcs')) ?: 'pcs',
                    'unit_price' => Format::parseIdr($l['unit_price'] ?? 0),
                    'mult_enabled' => !empty($l['mult_enabled']) ? 1 : 0,
                    'mult_label' => trim((string)($l['mult_label'] ?? '')) ?: null,
                    'mult_value' => isset($l['mult_value']) && $l['mult_value'] !== '' ? Format::parseQty($l['mult_value']) : null,
                    'tax_rate_id' => $taxId,
                    'tax_rate' => $rate,
                    'waived' => $waived,
                    'price_mode' => $priceMode,
                ];
            }
            $sections[] = ['title' => trim((string)($sec['title'] ?? '')), 'description' => trim((string)($sec['description'] ?? '')), 'lines' => $lines];
        }
        $flat = [];
        foreach ($sections as $s) foreach ($s['lines'] as $l) $flat[] = $l;
        $calc = Calculator::calculate($flat, $post['discount_type'] ?? 'none', (float)($post['discount_value'] ?? 0), $post['dp_type'] ?? 'none', (float)($post['dp_value'] ?? 0));
        return [
            'customer_id' => ($post['customer_id'] ?? '') === '' ? null : (int)$post['customer_id'],
            'doc_date' => $post['doc_date'] ?? date('Y-m-d'),
            'expiry_date' => $post['expiry_date'] ?? null,
            'due_date' => $post['due_date'] ?? null,
            'reference' => trim((string)($post['reference'] ?? '')),
            'discount_type' => in_array($post['discount_type'] ?? 'none', ['none','fixed','percent'], true) ? $post['discount_type'] : 'none',
            'discount_value' => $post['discount_type'] === 'percent' ? (float)($post['discount_value'] ?? 0) : Format::parseIdr($post['discount_value'] ?? 0),
            'dp_type' => in_array($post['dp_type'] ?? 'none', ['none','percent','fixed'], true) ? $post['dp_type'] : 'none',
            'dp_value' => $post['dp_type'] === 'percent' ? (float)($post['dp_value'] ?? 0) : Format::parseIdr($post['dp_value'] ?? 0),
            'dp_due_date' => ($post['dp_due_date'] ?? '') ?: null,
            'notes' => trim((string)($post['notes'] ?? '')),
            'terms' => trim((string)($post['terms'] ?? '')),
            'show_signature' => !empty($post['show_signature']) ? 1 : 0,
            'bank_ids' => array_values(array_unique(array_map('intval', (array)($post['bank_ids'] ?? [])))),
            'sections' => $sections,
            'calc' => $calc,
        ];
    }

    public static function saveDraft(PDO $db, string $kind, ?int $id, array $p, ?int $actor): int
    {
        $t = $kind === 'quote' ? 'quotations' : 'invoices';
        $dateCol = $kind === 'quote' ? 'expiry_date' : 'due_date';
        $dateVal = $kind === 'quote' ? ($p['expiry_date'] ?: null) : ($p['due_date'] ?: null);
        // fixed discount cap
        if ($p['discount_type'] === 'fixed') $p['discount_value'] = min($p['discount_value'], $p['calc']['subtotal']);
        if ($p['dp_type'] === 'fixed') $p['dp_value'] = min($p['dp_value'], $p['calc']['total']);
        if ($p['dp_type'] !== 'none' && $p['calc']['total'] <= 0) throw new \RuntimeException('Down payment requires a positive total.');
        if ($p['dp_type'] !== 'none' && $p['calc']['dp_amount'] <= 0) throw new \RuntimeException('Down payment must be greater than zero.');
        $sig = $kind === 'invoice' ? (int)($p['show_signature'] ?? 0) : null;
        $db->beginTransaction();
        try {
            if ($id) {
                $st = $db->prepare("SELECT status FROM $t WHERE id=? FOR UPDATE");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) throw new \RuntimeException('Document not found.');
                if ($row['status'] !== 'draft') {
                    // Issued invoices stay editable until money moves.
                    if (!($kind === 'invoice' && $row['status'] === 'issued' && !self::hasActivePayments($db, $id))) {
                        throw new \RuntimeException($row['status'] === 'issued' ? 'Cannot edit: payments already recorded.' : 'Only drafts can be edited.');
                    }
                }
                if ($kind === 'invoice' && self::invoiceHasSignature($db)) {
                    $u = $db->prepare("UPDATE $t SET customer_id=?, doc_date=?, $dateCol=?, reference=?, subtotal=?, discount_type=?, discount_value=?, discount_amount=?, tax_total=?, tax_breakdown=?, total=?, dp_type=?, dp_value=?, dp_amount=?, dp_due_date=?, notes=?, terms=?, show_signature=? WHERE id=?");
                    $u->execute([$p['customer_id'], $p['doc_date'], $dateVal, $p['reference'] ?: null, $p['calc']['subtotal'], $p['discount_type'], $p['discount_value'], $p['calc']['discount_amount'], $p['calc']['tax_total'], json_encode($p['calc']['tax_breakdown']), $p['calc']['total'], $p['dp_type'], $p['dp_value'], $p['calc']['dp_amount'], $p['dp_due_date'], $p['notes'] ?: null, $p['terms'] ?: null, $sig, $id]);
                } else {
                    $u = $db->prepare("UPDATE $t SET customer_id=?, doc_date=?, $dateCol=?, reference=?, subtotal=?, discount_type=?, discount_value=?, discount_amount=?, tax_total=?, tax_breakdown=?, total=?, dp_type=?, dp_value=?, dp_amount=?, dp_due_date=?, notes=?, terms=? WHERE id=?");
                    $u->execute([$p['customer_id'], $p['doc_date'], $dateVal, $p['reference'] ?: null, $p['calc']['subtotal'], $p['discount_type'], $p['discount_value'], $p['calc']['discount_amount'], $p['calc']['tax_total'], json_encode($p['calc']['tax_breakdown']), $p['calc']['total'], $p['dp_type'], $p['dp_value'], $p['calc']['dp_amount'], $p['dp_due_date'], $p['notes'] ?: null, $p['terms'] ?: null, $id]);
                }
            } else {
                if ($kind === 'invoice' && self::invoiceHasSignature($db)) {
                    $u = $db->prepare("INSERT INTO $t (customer_id, doc_date, $dateCol, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, show_signature, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $u->execute([$p['customer_id'], $p['doc_date'], $dateVal, $p['reference'] ?: null, $p['calc']['subtotal'], $p['discount_type'], $p['discount_value'], $p['calc']['discount_amount'], $p['calc']['tax_total'], json_encode($p['calc']['tax_breakdown']), $p['calc']['total'], $p['dp_type'], $p['dp_value'], $p['calc']['dp_amount'], $p['dp_due_date'], $p['notes'] ?: null, $p['terms'] ?: null, $sig, $actor]);
                } else {
                    $u = $db->prepare("INSERT INTO $t (customer_id, doc_date, $dateCol, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $u->execute([$p['customer_id'], $p['doc_date'], $dateVal, $p['reference'] ?: null, $p['calc']['subtotal'], $p['discount_type'], $p['discount_value'], $p['calc']['discount_amount'], $p['calc']['tax_total'], json_encode($p['calc']['tax_breakdown']), $p['calc']['total'], $p['dp_type'], $p['dp_value'], $p['calc']['dp_amount'], $p['dp_due_date'], $p['notes'] ?: null, $p['terms'] ?: null, $actor]);
                }
                $id = (int)$db->lastInsertId();
            }
            self::replaceSections($db, $kind, $id, $p);
            self::replaceBanks($db, $kind, $id, $p['bank_ids']);
            $db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function replaceSections(PDO $db, string $kind, int $docId, array $p): void
    {
        $secT = $kind === 'quote' ? 'quotation_sections' : 'invoice_sections';
        $lineT = $kind === 'quote' ? 'quotation_lines' : 'invoice_lines';
        $fk = $kind === 'quote' ? 'quotation_id' : 'invoice_id';
        $db->prepare("DELETE FROM $secT WHERE $fk=?")->execute([$docId]);
        $li = 0;
        foreach (array_values($p['sections']) as $si => $sec) {
            $db->prepare("INSERT INTO $secT ($fk, title, description, sort_order) VALUES (?,?,?,?)")
                ->execute([$docId, $sec['title'], $sec['description'] ?: null, $si]);
            $secId = (int)$db->lastInsertId();
            foreach (array_values($sec['lines']) as $ord => $l) {
                $amt = $p['calc']['line_amounts'][$li] ?? Calculator::lineAmount($l);
                $tax = $p['calc']['line_taxes'][$li] ?? 0;
                $li++;
                $db->prepare("INSERT INTO $lineT (section_id, product_id, description, quantity, unit_label, unit_price, mult_enabled, mult_label, mult_value, tax_rate_id, tax_rate_snapshot, waived, price_mode, line_amount, tax_amount, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$secId, $l['product_id'], $l['description'], $l['quantity'], $l['unit_label'], $l['unit_price'], $l['mult_enabled'], $l['mult_label'], $l['mult_enabled'] ? $l['mult_value'] : null, $l['tax_rate_id'], $l['tax_rate'], $l['waived'], $l['price_mode'], $amt, $tax, $ord]);
            }
        }
    }

    private static function replaceBanks(PDO $db, string $kind, int $docId, array $bankIds): void
    {
        $t = $kind === 'quote' ? 'quotation_banks' : 'invoice_banks';
        $fk = $kind === 'quote' ? 'quotation_id' : 'invoice_id';
        $db->prepare("DELETE FROM $t WHERE $fk=?")->execute([$docId]);
        foreach (array_values($bankIds) as $i => $bid) {
            $b = $db->prepare('SELECT * FROM bank_accounts WHERE id=?')->execute([$bid]) ? null : null;
            $s = $db->prepare('SELECT * FROM bank_accounts WHERE id=?'); $s->execute([$bid]);
            $row = $s->fetch();
            if (!$row) continue;
            $snap = json_encode(['bank_name'=>$row['bank_name'],'account_number'=>$row['account_number'],'holder'=>$row['holder'],'branch'=>$row['branch'],'note'=>$row['note']], JSON_UNESCAPED_UNICODE);
            $db->prepare("INSERT INTO $t ($fk, bank_id, snapshot, sort_order) VALUES (?,?,?,?)")->execute([$docId, $bid, $snap, $i]);
        }
    }

    public static function issue(PDO $db, string $kind, int $id, ?int $actor): string
    {
        $t = $kind === 'quote' ? 'quotations' : 'invoices';
        $prefix = Repo::settings($db)[$kind === 'quote' ? 'quote_prefix' : 'invoice_prefix'] ?? ($kind === 'quote' ? 'QT' : 'INV');
        $db->beginTransaction();
        try {
            $st = $db->prepare("SELECT * FROM $t WHERE id=? FOR UPDATE");
            $st->execute([$id]);
            $doc = $st->fetch();
            if (!$doc) throw new \RuntimeException('Not found.');
            if ($doc['status'] !== 'draft') throw new \RuntimeException('Only drafts can be issued.');
            // snapshot company + customer
            $settings = Repo::settings($db);
            unset($settings['id']);
            $cust = null;
            if ($doc['customer_id']) {
                $c = $db->prepare('SELECT * FROM customers WHERE id=?'); $c->execute([$doc['customer_id']]);
                $cust = $c->fetch() ?: null;
            }
            $year = (int) date('Y', strtotime($doc['doc_date']));
            $month = (int) date('n', strtotime($doc['doc_date']));
            $number = Repo::nextNumber($db, $kind === 'quote' ? 'QT' : 'INV', $prefix, $year, $month);
            $u = $db->prepare("UPDATE $t SET number=?, status='issued', company_snapshot=?, customer_snapshot=? WHERE id=?");
            $u->execute([$number, json_encode($settings, JSON_UNESCAPED_UNICODE), $cust ? json_encode($cust, JSON_UNESCAPED_UNICODE) : null, $id]);
            Repo::audit($db, $kind, $id, 'issue', $actor, $number);
            $db->commit();
            return $number;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function load(PDO $db, string $kind, int $id): ?array
    {
        $t = $kind === 'quote' ? 'quotations' : 'invoices';
        $secT = $kind === 'quote' ? 'quotation_sections' : 'invoice_sections';
        $lineT = $kind === 'quote' ? 'quotation_lines' : 'invoice_lines';
        $bankT = $kind === 'quote' ? 'quotation_banks' : 'invoice_banks';
        $fk = $kind === 'quote' ? 'quotation_id' : 'invoice_id';
        $st = $db->prepare("SELECT d.*, c.name AS customer_name FROM $t d LEFT JOIN customers c ON c.id=d.customer_id WHERE d.id=?");
        $st->execute([$id]);
        $doc = $st->fetch();
        if (!$doc) return null;
        $s = $db->prepare("SELECT * FROM $secT WHERE $fk=? ORDER BY sort_order, id");
        $s->execute([$id]);
        $sections = $s->fetchAll();
        foreach ($sections as &$sec) {
            $l = $db->prepare("SELECT l.*, p.name AS product_name FROM $lineT l LEFT JOIN products p ON p.id=l.product_id WHERE section_id=? ORDER BY sort_order, id");
            $l->execute([$sec['id']]);
            $sec['lines'] = $l->fetchAll();
        }
        $b = $db->prepare("SELECT * FROM $bankT WHERE $fk=? ORDER BY sort_order");
        $b->execute([$id]);
        $doc['sections'] = $sections;
        $doc['banks'] = $b->fetchAll();
        if ($kind === 'invoice') {
            $p = $db->prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY pay_date, id");
            $p->execute([$id]);
            $doc['payments'] = $p->fetchAll();
            $doc['paid'] = array_sum(array_map(fn($r) => $r['voided_at'] ? 0 : (int)$r['amount'], $doc['payments']));
            $doc['dp_paid'] = array_sum(array_map(fn($r) => ($r['voided_at'] || $r['purpose'] !== 'dp') ? 0 : (int)$r['amount'], $doc['payments']));
            $doc['balance'] = max(0, (int)$doc['total'] - $doc['paid']);
        }
        return $doc;
    }

    public static function convert(PDO $db, int $qid, ?int $actor): int
    {
        $chk = $db->prepare('SELECT converted_invoice_id, status FROM quotations WHERE id=?');
        $chk->execute([$qid]);
        $ex = $chk->fetch();
        if (!$ex) throw new \RuntimeException('Quotation not found.');
        if ($ex['converted_invoice_id']) return (int)$ex['converted_invoice_id'];
        if ($ex['status'] !== 'accepted') throw new \RuntimeException('Only accepted quotations can be converted.');
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM quotations WHERE id=? FOR UPDATE'); $q->execute([$qid]);
            $quote = $q->fetch();
            if (!$quote) throw new \RuntimeException('Quotation not found.');
            if ($quote['status'] !== 'accepted') throw new \RuntimeException('Only accepted quotations can be converted.');
            if ($quote['converted_invoice_id']) return (int)$quote['converted_invoice_id'];
            $full = self::load($db, 'quote', $qid);
            $settings = Repo::settings($db);
            $due = date('Y-m-d', strtotime($full['doc_date'] . ' +' . (int)($settings['invoice_payment_days'] ?? 14) . ' days'));
            if (self::invoiceHasSignature($db)) {
                $ins = $db->prepare('INSERT INTO invoices (customer_id, doc_date, due_date, reference, source_quotation_id, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, show_signature, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([$full['customer_id'], $full['doc_date'], $due, $full['reference'], $qid, $full['subtotal'], $full['discount_type'], $full['discount_value'], $full['discount_amount'], $full['tax_total'], $full['tax_breakdown'], $full['total'], $full['dp_type'], $full['dp_value'], $full['dp_amount'], $full['dp_due_date'], $full['notes'], $full['terms'], 0, $actor]);
            } else {
                $ins = $db->prepare('INSERT INTO invoices (customer_id, doc_date, due_date, reference, source_quotation_id, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $ins->execute([$full['customer_id'], $full['doc_date'], $due, $full['reference'], $qid, $full['subtotal'], $full['discount_type'], $full['discount_value'], $full['discount_amount'], $full['tax_total'], $full['tax_breakdown'], $full['total'], $full['dp_type'], $full['dp_value'], $full['dp_amount'], $full['dp_due_date'], $full['notes'], $full['terms'], $actor]);
            }
            $iid = (int)$db->lastInsertId();
            foreach ($full['sections'] as $si => $sec) {
                $db->prepare('INSERT INTO invoice_sections (invoice_id, title, description, sort_order) VALUES (?,?,?,?)')->execute([$iid, $sec['title'], $sec['description'], $si]);
                $nsid = (int)$db->lastInsertId();
                foreach ($sec['lines'] as $ord => $l) {
                    $db->prepare('INSERT INTO invoice_lines (section_id, product_id, description, quantity, unit_label, unit_price, mult_enabled, mult_label, mult_value, tax_rate_id, tax_rate_snapshot, waived, price_mode, line_amount, tax_amount, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$nsid, $l['product_id'], $l['description'], $l['quantity'], $l['unit_label'], $l['unit_price'], $l['mult_enabled'], $l['mult_label'], $l['mult_value'], $l['tax_rate_id'], $l['tax_rate_snapshot'], $l['waived'], $l['price_mode'], $l['line_amount'], $l['tax_amount'], $ord]);
                }
            }
            foreach ($full['banks'] as $i => $b) {
                $db->prepare('INSERT INTO invoice_banks (invoice_id, bank_id, snapshot, sort_order) VALUES (?,?,?,?)')->execute([$iid, $b['bank_id'], $b['snapshot'], $i]);
            }
            $db->prepare('UPDATE quotations SET converted_invoice_id=? WHERE id=?')->execute([$iid, $qid]);
            Repo::audit($db, 'quote', $qid, 'convert', $actor, "invoice:$iid");
            $db->commit();
            return $iid;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function addPayment(PDO $db, int $iid, array $data, ?int $actor): int
    {
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE'); $st->execute([$iid]);
            $inv = $st->fetch();
            if (!$inv || $inv['status'] !== 'issued') throw new \RuntimeException('Only issued invoices can receive payments.');
            $s = $db->prepare('SELECT COALESCE(SUM(amount),0) AS paid FROM payments WHERE invoice_id=? AND voided_at IS NULL'); $s->execute([$iid]);
            $paid = (int)$s->fetch()['paid'];
            $amount = (int)$data['amount'];
            if ($amount <= 0) throw new \RuntimeException('Invalid amount.');
            if ($paid + $amount > (int)$inv['total']) throw new \RuntimeException('Payment exceeds outstanding balance.');
            if (($data['purpose'] ?? 'balance') === 'dp') {
                if ($inv['dp_type'] === 'none') throw new \RuntimeException('No down payment required on this invoice.');
                $d = $db->prepare("SELECT COALESCE(SUM(amount),0) AS dpp FROM payments WHERE invoice_id=? AND purpose='dp' AND voided_at IS NULL");
                $d->execute([$iid]); $dpPaid = (int)$d->fetch()['dpp'];
                if ($dpPaid + $amount > (int)$inv['dp_amount']) throw new \RuntimeException('Down-payment amount exceeds remaining requested down payment.');
            }
            $ins = $db->prepare("INSERT INTO payments (invoice_id, amount, pay_date, method, purpose, reference, note, created_by) VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$iid, $amount, $data['pay_date'], $data['method'] ?? 'Transfer', $data['purpose'] ?? 'balance', $data['reference'] ?: null, $data['note'] ?: null, $actor]);
            $pid = (int)$db->lastInsertId();
            Repo::audit($db, 'invoice', $iid, 'payment', $actor, "payment:$pid amount:$amount");
            $db->commit();
            return $pid;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
