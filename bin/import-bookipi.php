<?php
declare(strict_types=1);
/**
 * Bookipi history importer (first-deploy / one-way migration).
 *
 * Pull (needs your Bookipi login):
 *   node bookipi.js init
 *   bookipi invoice list --limit 200 --json > invoices.json   (repeat -p 2.. if needed, merge arrays)
 *   bookipi customer list --limit 200 --json > customers.json
 *   bookipi item list --limit 200 --json > items.json
 *
 * Import:
 *   php bin/import-bookipi.php --dir <dumps-dir> [--dry-run] [--actor <user-id>]
 *
 * All history comes in as frozen issued records with ORIGINAL Bookipi numbers
 * preserved (our QT/INV counter is untouched). Voided -> cancelled, drafts -> drafts.
 * Payments come in as-is (purpose=balance). Idempotent: re-running skips existing numbers.
 *
 * Total fidelity: imported totals always equal the Bookipi totals. Bookipi taxes
 * that behave as withholdings (total = subtotal - discount - tax) are folded into
 * the fixed discount and explained in the notes; classic exclusive taxes that add
 * up (total = subtotal - discount + tax) are stored as real tax rates.
 */
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

use App\Services\Calculator;
use App\Support\DB;
use App\Support\Format;
use App\Support\Repo;

$opts = getopt('', ['dir:', 'dry-run', 'actor::']);
$dir = $opts['dir'] ?? null;
if (!$dir || !is_dir($dir)) { fwrite(STDERR, "Usage: php bin/import-bookipi.php --dir <dumps-dir> [--dry-run] [--actor <id>]\n"); exit(1); }
$dryRun = isset($opts['dry-run']);
$actor = isset($opts['actor']) ? (int) $opts['actor'] : null;

function loadJsonFile(string $path): array
{
    if (!file_exists($path)) return [];
    $raw = trim(file_get_contents($path));
    if ($raw === '') return [];
    // strip UTF-8 BOM (PowerShell redirection / editors)
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $data = json_decode($raw, true);
    if (is_array($data)) {
        // Bookipi CLI wrappers: {searchCustomers:{items:[...]}}, {searchItems:{items:[...]}}
        foreach (['searchCustomers', 'searchItems'] as $w) {
            if (isset($data[$w]['items']) && is_array($data[$w]['items'])) return $data[$w]['items'];
        }
        if (isset($data['customers']) || isset($data['invoices']) || isset($data['items'])) {
            foreach (['customers', 'invoices', 'items'] as $k) if (isset($data[$k])) return $data[$k];
        }
        return array_is_list($data) ? $data : [$data];
    }
    // NDJSON fallback (invoice get appended per line)
    $rows = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row)) $rows[] = $row;
    }
    return $rows;
}
/** First non-empty value among aliases. */
function pick(array $row, array $keys, $def = null)
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') return $row[$k];
    }
    return $def;
}

$pdo = DB::pdo();
$settings = Repo::settings($pdo);
$taxRows = $pdo->query('SELECT * FROM tax_rates')->fetchAll();
$taxByRate = [];
foreach ($taxRows as $t) $taxByRate[number_format((float) $t['rate'], 2, '.', '')] = (int) $t['id'];

$report = ['customers_created' => 0, 'customers_matched' => 0, 'items_created' => 0, 'items_matched' => 0,
    'invoices_imported' => 0, 'invoices_skipped' => 0, 'payments_imported' => 0, 'warnings' => [], 'errors' => []];
$warn = function (string $m) use (&$report) { $report['warnings'][] = $m; echo "WARN $m\n"; };

function findCustomer(PDO $db, ?string $email, ?string $name): ?array
{
    if ($email) {
        $st = $db->prepare('SELECT * FROM customers WHERE email=? LIMIT 1');
        $st->execute([$email]);
        if ($r = $st->fetch()) return $r;
    }
    if ($name) {
        $st = $db->prepare('SELECT * FROM customers WHERE name=? LIMIT 1');
        $st->execute([$name]);
        if ($r = $st->fetch()) return $r;
    }
    return null;
}

// ---- 1. customers ----
foreach (loadJsonFile($dir . '/customers.json') as $c) {
    if (!is_array($c)) continue;
    if (!empty($c['isTrashed']) || !empty($c['deleted'])) continue;
    $bc = bookipiCustomer($c);
    if ($bc['name'] === '') { $warn('customer row without name skipped'); continue; }
    if (findCustomer($pdo, $bc['email'], $bc['name'])) { $report['customers_matched']++; continue; }
    if ($dryRun) { $report['customers_created']++; continue; }
    $pdo->prepare('INSERT INTO customers (name, contact_person, email, phone, billing_address, tax_id) VALUES (?,?,?,?,?,?)')
        ->execute([$bc['name'], $bc['contact'], $bc['email'], $bc['phone'], $bc['address'], $bc['tax_id']]);
    $report['customers_created']++;
}

// ---- 2. items -> products ----
$unitMap = ['day' => 'days', 'days' => 'days', 'hour' => 'hours', 'hours' => 'hours', 'none' => 'pcs', '' => 'pcs'];
foreach (loadJsonFile($dir . '/items.json') as $it) {
    if (!is_array($it)) continue;
    if (!empty($it['isTrashed']) || !empty($it['deleted'])) continue;
    $name = trim((string) (pick($it, ['name', 'title', 'item'], '')));
    if ($name === '') { $warn('item row without name skipped'); continue; }
    $st = $pdo->prepare('SELECT id FROM products WHERE name=? LIMIT 1');
    $st->execute([$name]);
    if ($st->fetch()) { $report['items_matched']++; continue; }
    $report['items_created']++;
    if ($dryRun) continue;
    $price = max(0, (int) round((float) (pick($it, ['price', 'unit_price', 'rate', 'amount'], 0) ?? 0)));
    $rate = (float) (pick($it, ['taxRate', 'tax_rate', 'tax'], 0) ?? 0);
    $taxId = $taxByRate[number_format($rate, 2, '.', '')] ?? null;
    $unit = strtolower(trim((string) (pick($it, ['unitType', 'unit', 'unit_label', 'uom'], ''))));
    $unit = $unitMap[$unit] ?? (preg_match('/^[a-z ]{1,40}$/', $unit) ? $unit : 'pcs');
    $pdo->prepare('INSERT INTO products (name, description, unit_label, unit_price, tax_rate_id, mult_enabled_default, active) VALUES (?,?,?,?,?,0,1)')
        ->execute([$name, pick($it, ['description', 'desc'], null), $unit, $price, $taxId]);
}

// ---- 3+4. invoices + payments ----
/** ISO datetime -> Asia/Jakarta calendar date (Bookipi stores datetimes in UTC). */
function jakartaDate(mixed $iso, string $fallback): string
{
    $s = trim((string) ($iso ?? ''));
    if ($s === '') return $fallback;
    try {
        $dt = new DateTime($s);
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('Y-m-d');
    } catch (Throwable) {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : $fallback;
    }
}
/** Normalize a Bookipi customer object (nested or flat) to our customer fields. */
function bookipiCustomer(mixed $c): array
{
    if (!is_array($c)) return ['name' => trim((string) $c), 'email' => null, 'phone' => null, 'address' => null, 'tax_id' => null, 'contact' => null];
    $first = trim((string) ($c['firstName'] ?? ''));
    $last = trim((string) ($c['lastName'] ?? ''));
    $name = '';
    foreach (['companyName', 'individualCompanyName', 'name', 'company', 'customer'] as $k) {
        if (trim((string) ($c[$k] ?? '')) !== '') { $name = trim((string) $c[$k]); break; }
    }
    if ($name === '') $name = trim($first . ' ' . $last);
    $a = $c['address'] ?? [];
    $addr = is_array($a) ? implode(', ', array_filter([trim((string) ($a['address1'] ?? $c['address1'] ?? '')),
        trim((string) ($a['address2'] ?? $c['address2'] ?? '')), trim((string) ($a['address3'] ?? $c['address3'] ?? '')),
        trim((string) ($a['city'] ?? '')), trim((string) ($a['state'] ?? '')), trim((string) ($a['zip'] ?? ''))])) : null;
    if ($addr === '') $addr = null;
    $phone = pick($c, ['mobile', 'phone'], null) ?: null;
    $email = pick($c, ['email'], null) ?: null;
    return ['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $addr,
        'tax_id' => pick($c, ['businessNumber'], null) ?: null,
        'contact' => ($first !== '' || $last !== '') ? trim($first . ' ' . $last) : null];
}
$statusMap = ['paid' => 'issued', 'sent' => 'issued', 'viewed' => 'issued', 'read' => 'issued',
    'overdue' => 'issued', 'partialpaid' => 'issued', 'partially_paid' => 'issued', 'partial' => 'issued',
    'saved' => 'issued', 'open' => 'issued', 'unpaid' => 'issued',
    'void' => 'cancelled', 'voided' => 'cancelled', 'cancelled' => 'cancelled', 'draft' => 'draft'];
$details = loadJsonFile($dir . '/invoice_details.json');
if (!$details) $details = loadJsonFile($dir . '/invoices.json');

foreach ($details as $inv) {
    if (!is_array($inv)) continue;
    unset($calc); // never reuse the previous invoice's totals
    $number = pick($inv, ['no', 'docNumber', 'number', 'invoice_number', 'invoice_no'], null);
    $number = ($number !== null && trim((string) $number) !== '') ? trim((string) $number) : null;
    // status: paymentStatus wins (paid/not_paid); trashed => cancelled
    $payStatus = strtolower(trim((string) ($inv['paymentStatus'] ?? '')));
    $rawStatus = strtolower(trim((string) (pick($inv, ['computedStatus', 'status'], 'issued'))));
    if (!empty($inv['isTrashed'])) $rawStatus = 'voided';
    $status = $statusMap[$payStatus] ?? $statusMap[$rawStatus] ?? null;
    if ($status === null) { $warn("invoice " . ($number ?? '?') . ": unknown status '$rawStatus/$payStatus', treated as issued"); $status = 'issued'; }
    $currency = strtoupper(trim((string) (pick($inv, ['currency', 'cur'], 'IDR'))));
    if (!in_array($currency, ['IDR', 'RP', 'RP.', ''], true)) {
        $report['invoices_skipped']++;
        $warn("invoice " . ($number ?? '?') . ": non-IDR currency '$currency' skipped (app is IDR-only)");
        continue;
    }
    // idempotency (also guards duplicate Bookipi numbers)
    if ($number !== null) {
        $st = $pdo->prepare('SELECT id, total FROM invoices WHERE number=? LIMIT 1');
        $st->execute([$number]);
        if ($row = $st->fetch()) { $report['invoices_skipped']++; $warn("invoice $number: duplicate number, kept existing (total {$row['total']})"); continue; }
    }
    // customer (match or auto-create from embedded info)
    $bc = bookipiCustomer(pick($inv, ['customer', 'customerReference', 'client'], []));
    $cust = ($bc['name'] !== '' || $bc['email']) ? findCustomer($pdo, $bc['email'], $bc['name'] !== '' ? $bc['name'] : null) : null;
    if (!$cust && $bc['name'] !== '') {
        if ($dryRun) { $cust = ['id' => null, 'name' => $bc['name']]; $report['customers_created']++; }
        else {
            $pdo->prepare('INSERT INTO customers (name, contact_person, email, phone, billing_address, tax_id) VALUES (?,?,?,?,?,?)')
                ->execute([$bc['name'], $bc['contact'], $bc['email'], $bc['phone'], $bc['address'], $bc['tax_id']]);
            $cust = findCustomer($pdo, $bc['email'], $bc['name']);
            $report['customers_created']++;
        }
    }
    if (!$cust) { $report['errors'][] = "invoice " . ($number ?? '?') . ': no customer, skipped'; continue; }
    // lines (Bookipi: name + description, price, quantity; unit labels not provided -> pcs)
    $rawLines = pick($inv, ['line_items', 'items', 'lines'], []);
    if (!is_array($rawLines) || !$rawLines) { $report['errors'][] = "invoice " . ($number ?? '?') . ': no line items, skipped'; continue; }
    $lines = [];
    $lineFold = 0; // Bookipi line-level discounts don't exist in our model -> fold into doc discount
    foreach ($rawLines as $l) {
        if (!is_array($l)) continue;
        $lname = trim((string) (pick($l, ['name', 'title', 'item'], '')));
        $ldesc = trim((string) (pick($l, ['description', 'desc'], '')));
        $desc = $lname !== '' && $ldesc !== '' ? $lname . "\n" . $ldesc : ($lname !== '' ? $lname : ($ldesc !== '' ? $ldesc : '(no description)'));
        $qty = Format::parseQty(pick($l, ['quantity', 'qty', 'count'], 1));
        if ($qty <= 0) { $warn("invoice " . ($number ?? '?') . ": line '$lname' has qty<=0, skipped"); continue; }
        $uprice = max(0, (int) round((float) (pick($l, ['unit_price', 'price', 'rate', 'amount'], 0) ?? 0)));
        if ($qty > 9999999) {
            // pathological quantity used as a fixed amount (e.g. Deposit 15000000 x Rp1)
            $warn("invoice " . ($number ?? '?') . ": line '$lname' qty $qty collapsed to 1 x line total");
            $uprice = (int) round($qty * $uprice);
            $qty = 1.0;
        }
        $lineFold += max(0, (int) round((float) (pick($l, ['discount', 'line_discount'], 0) ?? 0)));
        $lines[] = ['product_id' => null, 'description' => $desc, 'quantity' => $qty, 'unit_label' => 'pcs',
            'unit_price' => $uprice,
            'mult_enabled' => 0, 'mult_label' => null, 'mult_value' => null,
            'tax_rate_id' => null, 'tax_rate' => 0.0, 'waived' => 0, 'price_mode' => 'normal'];
    }
    if (!$lines) { $report['errors'][] = "invoice " . ($number ?? '?') . ': no valid lines, skipped'; continue; }
    $docDate = jakartaDate(pick($inv, ['date', 'issue_date', 'invoice_date', 'created', 'createdAt'], null), date('Y-m-d'));
    $dueDate = !empty($inv['removeDueDate']) ? null : jakartaDate(pick($inv, ['dueDate', 'due_date', 'due'], null), '');
    if ($dueDate === '') $dueDate = null;
    $bookTotal = (int) round((float) (pick($inv, ['total', 'amount', 'grand_total'], 0) ?? 0));
    $baseFixed = max(0, (int) round((float) (pick($inv, ['discount', 'discount_amount', 'discount_fixed'], 0) ?? 0))) + $lineFold;
    $pctOff = (float) (pick($inv, ['discountPercentage', 'discount_percent', 'discount_pct'], 0) ?? 0);
    $taxPct = (float) (pick($inv, ['taxPercentage', 'tax_percentage', 'tax_rate', 'tax'], 0) ?? 0);
    $taxFigure = max(0, (int) round((float) ($inv['tax1'] ?? 0)));
    $dtype = 'fixed';
    $dval = 0.0;
    $noteExtra = '';
    if ($pctOff > 0 && $pctOff <= 100 && $taxPct <= 0) {
        // percent discount, no tax: totals must match exactly or fall through to plug logic
        $tryCalc = Calculator::calculate($lines, 'percent', $pctOff, 'none', 0);
        if ($tryCalc['total'] === $bookTotal) {
            $dtype = 'percent';
            $dval = $pctOff;
            $calc = $tryCalc;
        }
    }
    if ($taxPct > 0) {
        // Try A: classic exclusive tax (total = sub - disc + tax).
        $tryLines = array_map(function ($l) use ($taxPct, $taxByRate) {
            $l['tax_rate'] = $taxPct;
            $l['tax_rate_id'] = $taxByRate[number_format($taxPct, 2, '.', '')] ?? null;
            return $l;
        }, $lines);
        $tryCalc = Calculator::calculate($tryLines, 'fixed', $baseFixed, 'none', 0);
        if ($tryCalc['total'] === $bookTotal) {
            $lines = $tryLines;
            $dval = (float) $baseFixed;
            $calc = $tryCalc;
        }
    }
    if (!isset($calc)) {
        // Plan B: Bookipi total wins (withholding-style or tax-ignored invoices).
        // Fold the residual into the fixed discount so stored total == Bookipi total.
        $dtype = 'fixed';
        $plain = Calculator::calculate($lines, 'fixed', $baseFixed, 'none', 0);
        $plug = $plain['total'] - $bookTotal;
        if ($plug < 0) {
            $warn("invoice " . ($number ?? '?') . ": Bookipi total $bookTotal exceeds computable {$plain['total']}, stored recomputed");
            $dval = (float) $baseFixed;
            $calc = $plain;
        } else {
            $dval = (float) ($baseFixed + $plug);
            $calc = Calculator::calculate($lines, 'fixed', $dval, 'none', 0);
            if ($taxFigure > 0) $noteExtra = "Pajak Bookipi Rp" . number_format($taxFigure, 0, ',', '.') . " ($taxPct%) dicatat sebagai pemotongan.";
        }
    }
    if ($calc['total'] !== $bookTotal) {
        $warn("invoice " . ($number ?? '?') . ": stored total {$calc['total']} != Bookipi $bookTotal");
    }
    // draft dedupe (no number): same customer + date + total + reference
    $projName = is_array($inv['projectReference'] ?? null) ? trim((string) ($inv['projectReference']['name'] ?? '')) : '';
    $ref = trim((string) (pick($inv, ['purchaseOrderNumber', 'reference', 'project', 'notes_subject'], ''))) ?: null;
    if ($ref === null && $projName !== '') $ref = $projName;
    if ($number === null && $status === 'draft') {
        $st = $pdo->prepare('SELECT id FROM invoices WHERE number IS NULL AND customer_id=? AND doc_date=? AND total=? AND reference<=>? LIMIT 1');
        $st->execute([$cust['id'], $docDate, $calc['total'], $ref]);
        if ($st->fetch()) { $report['invoices_skipped']++; continue; }
    }
    if ($dryRun) { $report['invoices_imported']++; continue; }
    $pdo->beginTransaction();
    try {
        $companySnap = $settings;
        unset($companySnap['id']);
        $pdo->prepare("INSERT INTO invoices (number, status, customer_id, customer_snapshot, company_snapshot, doc_date, due_date, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, cancel_reason, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$number, $status, $cust['id'], json_encode($cust, JSON_UNESCAPED_UNICODE), json_encode($companySnap, JSON_UNESCAPED_UNICODE),
                $docDate, $dueDate, $ref, $calc['subtotal'], $dtype, $dval, $calc['discount_amount'], $calc['tax_total'],
                json_encode($calc['tax_breakdown']),                 $calc['total'], 'none', 0, 0, null,
                trim(implode("\n", array_filter([pick($inv, ['note', 'notes'], null), $noteExtra, $projName !== '' ? "Proyek: $projName" : null]))) ?: null,
                pick($inv, ['ToS', 'terms'], null),
                $status === 'cancelled' ? 'imported as void from Bookipi' : null, $actor]);
        $iid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO invoice_sections (invoice_id, title, description, sort_order) VALUES (?,\'Imported items\',NULL,0)')->execute([$iid]);
        $secId = (int) $pdo->lastInsertId();
        foreach ($lines as $i => $l) {
            $pdo->prepare('INSERT INTO invoice_lines (section_id, product_id, description, quantity, unit_label, unit_price, mult_enabled, mult_label, mult_value, tax_rate_id, tax_rate_snapshot, waived, price_mode, line_amount, tax_amount, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$secId, null, $l['description'], $l['quantity'], $l['unit_label'], $l['unit_price'], 0, null, null,
                    $l['tax_rate_id'], $l['tax_rate'], 0, 'normal', $calc['line_amounts'][$i], $calc['line_taxes'][$i], $i]);
        }
        $payCount = 0;
        $rawPays = pick($inv, ['payments', 'payment_history', 'transactions'], []);
        if (is_array($rawPays)) foreach ($rawPays as $p) {
            if (!is_array($p)) continue;
            $amt = max(0, Format::parseIdr(pick($p, ['amount', 'total', 'paid'], 0)));
            if ($amt <= 0) continue;
            $pdo->prepare("INSERT INTO payments (invoice_id, amount, pay_date, method, purpose, reference, note, created_by) VALUES (?,?,?,?,'balance',?,?,?)")
                ->execute([$iid, $amt, jakartaDate(pick($p, ['date', 'pay_date', 'payment_date'], null), $docDate),
                    substr(trim((string) pick($p, ['method', 'type', 'channel'], 'Transfer')) ?: 'Transfer', 0, 80),
                    pick($p, ['reference', 'ref', 'chargeId'], null), pick($p, ['note'], null) ?: null, $actor]);
            $payCount++;
            $report['payments_imported']++;
        }
        if ($status === 'issued' && $payCount > 0) {
            $s = $pdo->prepare('SELECT COALESCE(SUM(amount),0) paid FROM payments WHERE invoice_id=? AND voided_at IS NULL');
            $s->execute([$iid]);
            if ((int) $s->fetch()['paid'] > $calc['total']) $warn("invoice " . ($number ?? $iid) . ': payments exceed total (kept as-is)');
        }
        Repo::audit($pdo, 'invoice', $iid, 'import', $actor, 'bookipi:' . ($number ?? "draft-$iid"));
        $pdo->commit();
        $report['invoices_imported']++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $report['errors'][] = "invoice " . ($number ?? '?') . ': ' . $e->getMessage();
    }
}

echo "-----\n";
echo "customers: +{$report['customers_created']} created, {$report['customers_matched']} matched\n";
echo "items: +{$report['items_created']} created, {$report['items_matched']} matched\n";
echo "invoices: {$report['invoices_imported']} imported, {$report['invoices_skipped']} skipped\n";
echo "payments: {$report['payments_imported']} imported\n";
echo "warnings: " . count($report['warnings']) . ", errors: " . count($report['errors']) . "\n";
foreach ($report['errors'] as $e) echo "ERROR $e\n";
file_put_contents(rtrim($dir, '/\\') . '/import-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo ($dryRun ? 'DRY RUN - nothing written.' : 'Report saved to import-report.json') . "\n";
exit($report['errors'] ? 1 : 0);
