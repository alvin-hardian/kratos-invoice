<?php
declare(strict_types=1);
namespace App\Mcp;

use App\Services\Calculator;
use App\Services\DocService;
use App\Support\Format;
use App\Support\Repo;
use PDO;
use RuntimeException;

/**
 * MCP tool definitions + dispatch. Reuses DocService/Calculator/PDO so the
 * agent gets the same validation, transactions and totals as the web UI.
 */
final class ToolRegistry
{
    private const WRITE_TOOLS = [
        'create_customer', 'update_customer',
        'create_quotation_draft', 'update_quotation_draft',
        'issue_quotation', 'set_quotation_status', 'duplicate_quotation',
        'convert_quotation_to_invoice',
        'create_invoice_draft', 'update_invoice_draft',
        'issue_invoice', 'cancel_invoice',
        'record_payment', 'void_payment',
    ];

    public static function isWrite(string $name): bool
    {
        return in_array($name, self::WRITE_TOOLS, true);
    }

    public static function names(): array
    {
        return array_column(self::definitions(), 'name');
    }

    // ---------- JSON Schemas ----------

    private static function lineSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['description', 'quantity', 'unit_price'],
            'properties' => [
                'description' => ['type' => 'string', 'description' => 'Line description (required)'],
                'quantity' => ['type' => ['number', 'string'], 'description' => 'Quantity > 0, up to 3 decimals'],
                'unit_label' => ['type' => 'string', 'default' => 'pcs'],
                'unit_price' => ['type' => ['integer', 'string'], 'description' => 'Whole IDR (e.g. 150000 or "Rp 150.000")'],
                'product_id' => ['type' => ['integer', 'string'], 'description' => 'Reusable product id (optional, defaults only)'],
                'mult_enabled' => ['type' => 'boolean', 'default' => false],
                'mult_label' => ['type' => 'string'],
                'mult_value' => ['type' => ['number', 'string']],
                'tax_rate_id' => ['type' => ['integer', 'string'], 'description' => 'Tax rate id; empty = no tax'],
                'tax_rate' => ['type' => ['number', 'string'], 'description' => 'Ad-hoc rate 0-100 if no tax_rate_id'],
                'waived' => ['type' => 'boolean', 'default' => false],
                'price_mode' => ['type' => 'string', 'enum' => ['normal', 'waived', 'compliment', 'included'], 'default' => 'normal'],
            ],
        ];
    }

    private static function sectionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'lines'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Section title (required)'],
                'description' => ['type' => 'string'],
                'lines' => ['type' => 'array', 'minItems' => 1, 'items' => self::lineSchema()],
            ],
        ];
    }

    private static function docProps(string $dateField): array
    {
        return [
            'customer_id' => ['type' => 'integer', 'description' => 'Customer id (required)'],
            'doc_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (required)'],
            $dateField => ['type' => 'string', 'description' => 'YYYY-MM-DD (expiry for quotes, due for invoices)'],
            'reference' => ['type' => 'string'],
            'discount_type' => ['type' => 'string', 'enum' => ['none', 'fixed', 'percent'], 'default' => 'none'],
            'discount_value' => ['type' => ['number', 'string'], 'description' => 'Percent 0-100 or whole IDR'],
            'dp_type' => ['type' => 'string', 'enum' => ['none', 'percent', 'fixed'], 'default' => 'none'],
            'dp_value' => ['type' => ['number', 'string']],
            'dp_due_date' => ['type' => 'string'],
            'notes' => ['type' => 'string'],
            'terms' => ['type' => 'string'],
            'bank_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'sections' => ['type' => 'array', 'minItems' => 1, 'items' => self::sectionSchema()],
        ];
    }

    public static function definitions(): array
    {
        $line = self::lineSchema();
        $section = self::sectionSchema();
        return [
            ['name' => 'dashboard_stats', 'description' => 'Outstanding receivables, overdue, this-month collections, open quotations, DP due.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_customers', 'description' => 'Search customers.', 'inputSchema' => ['type' => 'object', 'properties' => ['s' => ['type' => 'string'], 'arch' => ['type' => 'string', 'description' => '0|1 filter'], 'limit' => ['type' => 'integer', 'default' => 50]]]],
            ['name' => 'get_customer', 'description' => 'Customer with quotations and invoices.', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'list_products', 'description' => 'Search reusable items.', 'inputSchema' => ['type' => 'object', 'properties' => ['s' => ['type' => 'string'], 'active' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'default' => 50]]]],
            ['name' => 'get_product', 'description' => 'Single reusable item.', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'list_quotations', 'description' => 'Search quotations with customer + total.', 'inputSchema' => ['type' => 'object', 'properties' => ['s' => ['type' => 'string'], 'status' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'default' => 50]]]],
            ['name' => 'get_quotation', 'description' => 'Full quotation: sections, lines, banks, totals, audit log.', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'list_invoices', 'description' => 'Search invoices with paid/balance.', 'inputSchema' => ['type' => 'object', 'properties' => ['s' => ['type' => 'string'], 'status' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'default' => 50]]]],
            ['name' => 'get_invoice', 'description' => 'Full invoice: sections, lines, banks, payments, balance, audit log.', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'get_summary', 'description' => 'Period aggregates (issued/paid/outstanding/overdue/DP) + invoice rows.', 'inputSchema' => ['type' => 'object', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'customer_id' => ['type' => 'integer'], 's' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'default' => 100]]]],
            ['name' => 'preview_calculation', 'description' => 'Authoritative totals preview for flat lines (same policy as backend).', 'inputSchema' => ['type' => 'object', 'required' => ['lines'], 'properties' => ['lines' => ['type' => 'array', 'items' => $line], 'discount_type' => ['type' => 'string', 'default' => 'none'], 'discount_value' => ['type' => ['number', 'string'], 'default' => 0], 'dp_type' => ['type' => 'string', 'default' => 'none'], 'dp_value' => ['type' => ['number', 'string'], 'default' => 0]]]],
            ['name' => 'create_customer', 'description' => 'Create a customer.', 'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'contact_person' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'billing_address' => ['type' => 'string'], 'tax_id' => ['type' => 'string']]]],
            ['name' => 'update_customer', 'description' => 'Update a customer (partial fields merged).', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'contact_person' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'billing_address' => ['type' => 'string'], 'tax_id' => ['type' => 'string'], 'archived' => ['type' => 'integer']]]],
            ['name' => 'create_quotation_draft', 'description' => 'Create quotation draft. Needs customer_id, doc_date, ≥1 section with ≥1 line each.', 'inputSchema' => ['type' => 'object', 'required' => ['customer_id', 'doc_date', 'sections'], 'properties' => self::docProps('expiry_date')]],
            ['name' => 'update_quotation_draft', 'description' => 'Update a draft quotation (drafts only).', 'inputSchema' => ['type' => 'object', 'required' => ['id', 'customer_id', 'doc_date', 'sections'], 'properties' => array_merge(['id' => ['type' => 'integer']], self::docProps('expiry_date'))]],
            ['name' => 'issue_quotation', 'description' => 'Issue a draft quotation (assigns number QT-YYMMNNN).', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'set_quotation_status', 'description' => 'Accept/reject/cancel an issued quotation (accepted→cancelled allowed).', 'inputSchema' => ['type' => 'object', 'required' => ['id', 'to'], 'properties' => ['id' => ['type' => 'integer'], 'to' => ['type' => 'string', 'enum' => ['accepted', 'rejected', 'cancelled']], 'reason' => ['type' => 'string']]]],
            ['name' => 'duplicate_quotation', 'description' => 'Duplicate a quotation as a new draft.', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'convert_quotation_to_invoice', 'description' => 'Convert accepted quotation to invoice draft (duplicate-safe).', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'description' => 'Quotation id']]]],
            ['name' => 'create_invoice_draft', 'description' => 'Create invoice draft directly (without quotation).', 'inputSchema' => ['type' => 'object', 'required' => ['customer_id', 'doc_date', 'sections'], 'properties' => array_merge(self::docProps('due_date'), ['show_signature' => ['type' => 'boolean', 'description' => 'Show signature placeholder on the invoice PDF (director name comes from server .env)']])]],
            ['name' => 'update_invoice_draft', 'description' => 'Update a draft invoice (drafts only).', 'inputSchema' => ['type' => 'object', 'required' => ['id', 'customer_id', 'doc_date', 'sections'], 'properties' => array_merge(['id' => ['type' => 'integer']], self::docProps('due_date'), ['show_signature' => ['type' => 'boolean', 'description' => 'Show signature placeholder on the invoice PDF (director name comes from server .env)']])]],
            ['name' => 'issue_invoice', 'description' => 'Issue a draft invoice (assigns number INV-YYMMNNN).', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
            ['name' => 'cancel_invoice', 'description' => 'Cancel an issued invoice (blocked with active payments).', 'inputSchema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'reason' => ['type' => 'string']]]],
            ['name' => 'record_payment', 'description' => 'Record a manual payment (dp/balance/other) with overpay + DP-cap protection.', 'inputSchema' => ['type' => 'object', 'required' => ['invoice_id', 'amount'], 'properties' => ['invoice_id' => ['type' => 'integer'], 'amount' => ['type' => ['integer', 'string'], 'description' => 'Whole IDR'], 'pay_date' => ['type' => 'string'], 'method' => ['type' => 'string', 'default' => 'Transfer'], 'purpose' => ['type' => 'string', 'enum' => ['dp', 'balance', 'other'], 'default' => 'balance'], 'reference' => ['type' => 'string'], 'note' => ['type' => 'string']]]],
            ['name' => 'void_payment', 'description' => 'Void a payment with reason.', 'inputSchema' => ['type' => 'object', 'required' => ['payment_id'], 'properties' => ['payment_id' => ['type' => 'integer'], 'reason' => ['type' => 'string']]]],
        ];
    }

    // ---------- dispatch ----------

    public static function call(PDO $db, string $name, array $args): mixed
    {
        return match ($name) {
            'dashboard_stats' => self::dashboard($db),
            'list_customers' => self::listCustomers($db, $args),
            'get_customer' => self::getCustomer($db, $args),
            'list_products' => self::listProducts($db, $args),
            'get_product' => self::needOne($db, 'products', (int)($args['id'] ?? 0)),
            'list_quotations' => self::listQuotations($db, $args),
            'get_quotation' => self::getDoc($db, 'quote', (int)($args['id'] ?? 0)),
            'list_invoices' => self::listInvoices($db, $args),
            'get_invoice' => self::getDoc($db, 'invoice', (int)($args['id'] ?? 0)),
            'get_summary' => self::summary($db, $args),
            'preview_calculation' => self::preview($db, $args),
            'create_customer' => self::createCustomer($db, $args),
            'update_customer' => self::updateCustomer($db, $args),
            'create_quotation_draft' => self::saveDraft($db, 'quote', null, $args),
            'update_quotation_draft' => self::saveDraft($db, 'quote', (int)($args['id'] ?? 0), $args),
            'issue_quotation' => self::issue($db, 'quote', (int)($args['id'] ?? 0)),
            'set_quotation_status' => self::quoteStatus($db, $args),
            'duplicate_quotation' => self::duplicateQuote($db, (int)($args['id'] ?? 0)),
            'convert_quotation_to_invoice' => self::convert($db, (int)($args['id'] ?? 0)),
            'create_invoice_draft' => self::saveDraft($db, 'invoice', null, $args),
            'update_invoice_draft' => self::saveDraft($db, 'invoice', (int)($args['id'] ?? 0), $args),
            'issue_invoice' => self::issue($db, 'invoice', (int)($args['id'] ?? 0)),
            'cancel_invoice' => self::cancelInvoice($db, $args),
            'record_payment' => self::recordPayment($db, $args),
            'void_payment' => self::voidPayment($db, $args),
            default => throw new RuntimeException("Unknown tool: $name"),
        };
    }

    // ---------- helpers ----------

    private static function limit(array $args, int $def = 50, int $max = 200): int
    {
        return min($max, max(1, (int)($args['limit'] ?? $def)));
    }

    /** Decode JSON snapshot columns into arrays for agent-friendly output. */
    private static function cleanDoc(array $d): array
    {
        foreach (['customer_snapshot', 'company_snapshot', 'tax_breakdown'] as $k) {
            if (isset($d[$k]) && is_string($d[$k])) {
                $d[$k] = $d[$k] === '' ? null : json_decode($d[$k], true);
            }
        }
        foreach ($d['banks'] ?? [] as &$b) {
            if (isset($b['snapshot']) && is_string($b['snapshot'])) {
                $b['snapshot_decoded'] = json_decode($b['snapshot'], true);
            }
        }
        return $d;
    }

    private static function needOne(PDO $db, string $table, int $id): array
    {
        if ($id <= 0) throw new RuntimeException('id is required.');
        $allow = ['products' => true];
        if (!isset($allow[$table])) throw new RuntimeException('Not found.');
        $st = $db->prepare("SELECT * FROM `$table` WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Not found.');
        return $row;
    }

    // ---------- read tools ----------

    private static function dashboard(PDO $db): array
    {
        $one = fn(string $sql) => $db->query($sql)->fetch() ?: [];
        return [
            'outstanding' => (int)($one("SELECT COALESCE(SUM(i.total - COALESCE(p.paid,0)),0) v FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status='issued' AND i.total > COALESCE(p.paid,0)")['v'] ?? 0),
            'overdue' => (int)($one("SELECT COALESCE(SUM(i.total - COALESCE(p.paid,0)),0) v FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status='issued' AND i.due_date < CURDATE() AND i.total > COALESCE(p.paid,0)")['v'] ?? 0),
            'collected_this_month' => (int)($one("SELECT COALESCE(SUM(amount),0) v FROM payments WHERE voided_at IS NULL AND DATE_FORMAT(pay_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")['v'] ?? 0),
            'open_quotations' => (int)($one("SELECT COUNT(*) v FROM quotations WHERE status='issued'")['v'] ?? 0),
            'dp_due' => (int)($one("SELECT COALESCE(SUM(GREATEST(i.dp_amount - COALESCE(d.dp,0),0)),0) v FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) dp FROM payments WHERE purpose='dp' AND voided_at IS NULL GROUP BY invoice_id) d ON d.invoice_id=i.id WHERE i.status='issued' AND i.dp_type != 'none' AND i.dp_amount > COALESCE(d.dp,0)")['v'] ?? 0),
        ];
    }

    private static function listCustomers(PDO $db, array $args): array
    {
        $where = '1=1'; $par = [];
        if (!empty($args['s'])) { $where .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)'; $par = array_fill(0, 3, '%' . $args['s'] . '%'); }
        if (isset($args['arch']) && $args['arch'] !== '') { $where .= ' AND archived=?'; $par[] = (int)$args['arch']; }
        $st = $db->prepare("SELECT * FROM customers WHERE $where ORDER BY id DESC LIMIT " . self::limit($args));
        $st->execute($par);
        return $st->fetchAll();
    }

    private static function getCustomer(PDO $db, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('id is required.');
        $st = $db->prepare('SELECT * FROM customers WHERE id=?'); $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Customer not found.');
        $q = $db->prepare('SELECT id, number, status, doc_date, total FROM quotations WHERE customer_id=? ORDER BY id DESC LIMIT 50'); $q->execute([$id]);
        $i = $db->prepare('SELECT id, number, status, doc_date, total FROM invoices WHERE customer_id=? ORDER BY id DESC LIMIT 50'); $i->execute([$id]);
        return ['customer' => $c, 'quotations' => $q->fetchAll(), 'invoices' => $i->fetchAll()];
    }

    private static function listProducts(PDO $db, array $args): array
    {
        $where = '1=1'; $par = [];
        if (!empty($args['s'])) { $where .= ' AND (p.name LIKE ?)'; $par[] = '%' . $args['s'] . '%'; }
        if (isset($args['active']) && $args['active'] !== '') { $where .= ' AND p.active=?'; $par[] = (int)$args['active']; }
        $st = $db->prepare("SELECT p.*, t.code tax_code, t.rate tax_rate_val FROM products p LEFT JOIN tax_rates t ON t.id=p.tax_rate_id WHERE $where ORDER BY p.id DESC LIMIT " . self::limit($args));
        $st->execute($par);
        return $st->fetchAll();
    }

    private static function listQuotations(PDO $db, array $args): array
    {
        $where = '1=1'; $par = [];
        if (!empty($args['s'])) { $where .= ' AND (qq.number LIKE ? OR c.name LIKE ? OR qq.reference LIKE ?)'; $par = array_fill(0, 3, '%' . $args['s'] . '%'); }
        if (!empty($args['status'])) { $where .= ' AND qq.status=?'; $par[] = $args['status']; }
        $st = $db->prepare("SELECT qq.id, qq.number, qq.status, qq.doc_date, qq.expiry_date, qq.total, c.name customer_name FROM quotations qq LEFT JOIN customers c ON c.id=qq.customer_id WHERE $where ORDER BY qq.id DESC LIMIT " . self::limit($args));
        $st->execute($par);
        return $st->fetchAll();
    }

    private static function listInvoices(PDO $db, array $args): array
    {
        $where = '1=1'; $par = [];
        if (!empty($args['s'])) { $where .= ' AND (i.number LIKE ? OR c.name LIKE ? OR i.reference LIKE ?)'; $par = array_fill(0, 3, '%' . $args['s'] . '%'); }
        if (!empty($args['status'])) { $where .= ' AND i.status=?'; $par[] = $args['status']; }
        $st = $db->prepare("SELECT i.id, i.number, i.status, i.doc_date, i.due_date, i.total, c.name customer_name, COALESCE(p.paid,0) paid, GREATEST(i.total - COALESCE(p.paid,0),0) balance FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE $where ORDER BY i.id DESC LIMIT " . self::limit($args));
        $st->execute($par);
        return $st->fetchAll();
    }

    private static function getDoc(PDO $db, string $kind, int $id): array
    {
        if ($id <= 0) throw new RuntimeException('id is required.');
        $d = DocService::load($db, $kind, $id);
        if (!$d) throw new RuntimeException('Not found.');
        $logs = $db->prepare('SELECT * FROM audit_log WHERE entity_type=? AND entity_id=? ORDER BY id DESC LIMIT 50');
        $logs->execute([$kind === 'quote' ? 'quote' : 'invoice', $id]);
        $d = self::cleanDoc($d);
        $d['audit_log'] = $logs->fetchAll();
        return $d;
    }

    private static function summary(PDO $db, array $args): array
    {
        $from = $args['from'] ?? date('Y-m-01');
        $to = $args['to'] ?? date('Y-m-t');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to)) $to = date('Y-m-t');
        $cust = isset($args['customer_id']) && (int)$args['customer_id'] > 0 ? (int)$args['customer_id'] : null;
        $s = trim((string)($args['s'] ?? ''));

        $sum = function (string $sql, array $par) use ($db): int {
            $st = $db->prepare($sql); $st->execute($par);
            return (int)($st->fetch()['v'] ?? 0);
        };
        $cp = $cust ? ' AND d.customer_id=?' : '';
        $cpar = $cust ? [$cust] : [];
        $agg = [
            'from' => $from, 'to' => $to,
            'awaiting_quotations' => $sum("SELECT COALESCE(SUM(total),0) v FROM quotations d WHERE status='issued' AND doc_date BETWEEN ? AND ?$cp", [$from, $to, ...$cpar]),
            'accepted_quotations' => $sum("SELECT COALESCE(SUM(total),0) v FROM quotations d WHERE status='accepted' AND doc_date BETWEEN ? AND ?$cp", [$from, $to, ...$cpar]),
            'issued_invoices' => $sum("SELECT COALESCE(SUM(total),0) v FROM invoices d WHERE status='issued' AND doc_date BETWEEN ? AND ?$cp", [$from, $to, ...$cpar]),
            'paid' => $sum("SELECT COALESCE(SUM(p.amount),0) v FROM payments p JOIN invoices d ON d.id=p.invoice_id WHERE p.voided_at IS NULL AND p.pay_date BETWEEN ? AND ? AND d.status='issued'$cp", [$from, $to, ...$cpar]),
            'outstanding' => $sum("SELECT COALESCE(SUM(d.total-COALESCE(p.paid,0)),0) v FROM invoices d LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE d.status='issued' AND d.doc_date BETWEEN ? AND ?$cp", [$from, $to, ...$cpar]),
        ];
        $w = "d.status='issued' AND d.doc_date BETWEEN ? AND ?";
        $par = [$from, $to];
        if ($cust) { $w .= ' AND d.customer_id=?'; $par[] = $cust; }
        if ($s !== '') { $w .= ' AND (d.number LIKE ? OR c.name LIKE ? OR d.reference LIKE ?)'; $par[] = "%$s%"; $par[] = "%$s%"; $par[] = "%$s%"; }
        $st = $db->prepare("SELECT d.id, d.number, c.name customer_name, d.doc_date, d.due_date, d.total, d.dp_amount, COALESCE(p.paid,0) paid, GREATEST(d.total-COALESCE(p.paid,0),0) balance FROM invoices d LEFT JOIN customers c ON c.id=d.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE $w ORDER BY d.doc_date DESC, d.id DESC LIMIT " . self::limit($args, 100));
        $st->execute($par);
        $agg['rows'] = $st->fetchAll();
        return $agg;
    }

    private static function preview(PDO $db, array $args): array
    {
        $lines = $args['lines'] ?? null;
        if (!is_array($lines) || count($lines) === 0) throw new RuntimeException('lines[] is required.');
        $taxMap = Repo::taxMap($db);
        $flat = [];
        foreach ($lines as $l) {
            if (!is_array($l)) throw new RuntimeException('Each line must be an object.');
            $taxId = ($l['tax_rate_id'] ?? '') === '' ? null : (int)$l['tax_rate_id'];
            $rate = 0.0;
            if ($taxId && isset($taxMap[$taxId])) $rate = (float)$taxMap[$taxId]['rate'];
            elseif (isset($l['tax_rate']) && $l['tax_rate'] !== '') $rate = min(100, max(0, (float)$l['tax_rate']));
            $flat[] = [
                'quantity' => Format::parseQty($l['quantity'] ?? 1),
                'unit_price' => Format::parseIdr($l['unit_price'] ?? 0),
                'mult_enabled' => !empty($l['mult_enabled']) ? 1 : 0,
                'mult_value' => isset($l['mult_value']) && $l['mult_value'] !== '' ? Format::parseQty($l['mult_value']) : null,
                'tax_rate' => $rate,
                'waived' => !empty($l['waived']) ? 1 : 0,
            ];
        }
        $dt = $args['discount_type'] ?? 'none';
        $pt = $args['dp_type'] ?? 'none';
        $dv = $dt === 'percent' ? (float)($args['discount_value'] ?? 0) : (float)Format::parseIdr($args['discount_value'] ?? 0);
        $pv = $pt === 'percent' ? (float)($args['dp_value'] ?? 0) : (float)Format::parseIdr($args['dp_value'] ?? 0);
        return Calculator::calculate($flat, $dt, $dv, $pt, $pv);
    }

    // ---------- write tools ----------

    private static function createCustomer(PDO $db, array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') throw new RuntimeException('name is required.');
        $db->prepare('INSERT INTO customers (name, contact_person, email, phone, billing_address, tax_id) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $args['contact_person'] ?? null, $args['email'] ?? null, $args['phone'] ?? null, $args['billing_address'] ?? null, $args['tax_id'] ?? null]);
        $id = (int)$db->lastInsertId();
        Repo::audit($db, 'customer', $id, 'mcp_create', Auth::actorId(), null);
        $st = $db->prepare('SELECT * FROM customers WHERE id=?'); $st->execute([$id]);
        return $st->fetch() ?: ['id' => $id];
    }

    private static function updateCustomer(PDO $db, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('id is required.');
        $st = $db->prepare('SELECT * FROM customers WHERE id=?'); $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) throw new RuntimeException('Customer not found.');
        $row = [
            $args['name'] ?? $cur['name'],
            $args['contact_person'] ?? $cur['contact_person'],
            $args['email'] ?? $cur['email'],
            $args['phone'] ?? $cur['phone'],
            $args['billing_address'] ?? $cur['billing_address'],
            $args['tax_id'] ?? $cur['tax_id'],
            isset($args['archived']) ? (int)$args['archived'] : (int)$cur['archived'],
            $id,
        ];
        if (trim((string)$row[0]) === '') throw new RuntimeException('name is required.');
        $db->prepare('UPDATE customers SET name=?, contact_person=?, email=?, phone=?, billing_address=?, tax_id=?, archived=? WHERE id=?')->execute($row);
        Repo::audit($db, 'customer', $id, 'mcp_update', Auth::actorId(), null);
        $st = $db->prepare('SELECT * FROM customers WHERE id=?'); $st->execute([$id]);
        return $st->fetch() ?: ['id' => $id];
    }

    private static function saveDraft(PDO $db, string $kind, ?int $id, array $args): array
    {
        $n = PayloadAdapter::normalize($db, $args);
        if ($errs = Calculator::validateDocument(['customer_id' => $n['customer_id'], 'doc_date' => $n['doc_date'], 'sections' => $n['sections'], 'discount_type' => $n['discount_type'], 'discount_value' => $n['discount_value']])) {
            throw new RuntimeException(implode(' ', $errs));
        }
        $newId = DocService::saveDraft($db, $kind, $id, $n, Auth::actorId());
        Repo::audit($db, $kind, $newId, 'mcp_save_draft', Auth::actorId(), null);
        return [
            'id' => $newId,
            'subtotal' => $n['calc']['subtotal'],
            'discount_amount' => $n['calc']['discount_amount'],
            'tax_total' => $n['calc']['tax_total'],
            'total' => $n['calc']['total'],
            'dp_amount' => $n['calc']['dp_amount'],
        ];
    }

    private static function issue(PDO $db, string $kind, int $id): array
    {
        if ($id <= 0) throw new RuntimeException('id is required.');
        $num = DocService::issue($db, $kind, $id, Auth::actorId());
        return ['id' => $id, 'number' => $num];
    }

    private static function quoteStatus(PDO $db, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $to = (string)($args['to'] ?? '');
        if ($id <= 0) throw new RuntimeException('id is required.');
        if (!in_array($to, ['accepted', 'rejected', 'cancelled'], true)) throw new RuntimeException("to must be accepted|rejected|cancelled.");
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT status FROM quotations WHERE id=? FOR UPDATE'); $st->execute([$id]);
            $cur = $st->fetch()['status'] ?? null;
            if ($cur === null) throw new RuntimeException('Quotation not found.');
            if (!($cur === 'issued' || ($cur === 'accepted' && $to === 'cancelled'))) {
                throw new RuntimeException('Allowed only from issued (accept/reject/cancel).');
            }
            $db->prepare('UPDATE quotations SET status=?, cancel_reason=? WHERE id=?')->execute([$to, $args['reason'] ?? null, $id]);
            Repo::audit($db, 'quote', $id, 'mcp_' . $to, Auth::actorId(), isset($args['reason']) ? (string)$args['reason'] : null);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'status' => $to];
    }

    private static function duplicateQuote(PDO $db, int $id): array
    {
        if ($id <= 0) throw new RuntimeException('id is required.');
        $d = DocService::load($db, 'quote', $id);
        if (!$d) throw new RuntimeException('Quotation not found.');
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO quotations (customer_id, doc_date, expiry_date, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, created_by) SELECT customer_id, CURDATE(), expiry_date, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, ? FROM quotations WHERE id=?')->execute([Auth::actorId(), $id]);
            $nid = (int)$db->lastInsertId();
            foreach ($d['sections'] as $si => $sec) {
                $db->prepare('INSERT INTO quotation_sections (quotation_id, title, description, sort_order) VALUES (?,?,?,?)')->execute([$nid, $sec['title'], $sec['description'], $si]);
                $ns = (int)$db->lastInsertId();
                foreach ($sec['lines'] as $ord => $l) {
                    $db->prepare('INSERT INTO quotation_lines (section_id, product_id, description, quantity, unit_label, unit_price, mult_enabled, mult_label, mult_value, tax_rate_id, tax_rate_snapshot, waived, price_mode, line_amount, tax_amount, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$ns, $l['product_id'], $l['description'], $l['quantity'], $l['unit_label'], $l['unit_price'], $l['mult_enabled'], $l['mult_label'], $l['mult_value'], $l['tax_rate_id'], $l['tax_rate_snapshot'], $l['waived'], $l['price_mode'], $l['line_amount'], $l['tax_amount'], $ord]);
                }
            }
            foreach ($d['banks'] as $i => $b) $db->prepare('INSERT INTO quotation_banks (quotation_id, bank_id, snapshot, sort_order) VALUES (?,?,?,?)')->execute([$nid, $b['bank_id'], $b['snapshot'], $i]);
            Repo::audit($db, 'quote', $nid, 'mcp_duplicate', Auth::actorId(), "from:$id");
            $db->commit();
            return ['id' => $nid, 'from_quotation_id' => $id];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function convert(PDO $db, int $qid): array
    {
        if ($qid <= 0) throw new RuntimeException('id is required.');
        $iid = DocService::convert($db, $qid, Auth::actorId());
        return ['quotation_id' => $qid, 'invoice_id' => $iid];
    }

    private static function cancelInvoice(PDO $db, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('id is required.');
        $c = $db->prepare('SELECT COUNT(*) c FROM payments WHERE invoice_id=? AND voided_at IS NULL'); $c->execute([$id]);
        if ((int)$c->fetch()['c'] > 0) throw new RuntimeException('Cannot cancel: active payments exist.');
        $st = $db->prepare("UPDATE invoices SET status='cancelled', cancel_reason=? WHERE id=? AND status='issued'");
        $st->execute([$args['reason'] ?? null, $id]);
        if ($st->rowCount() === 0) throw new RuntimeException('Only issued invoices can be cancelled.');
        Repo::audit($db, 'invoice', $id, 'mcp_cancel', Auth::actorId(), isset($args['reason']) ? (string)$args['reason'] : null);
        return ['id' => $id, 'status' => 'cancelled'];
    }

    private static function recordPayment(PDO $db, array $args): array
    {
        $iid = (int)($args['invoice_id'] ?? 0);
        if ($iid <= 0) throw new RuntimeException('invoice_id is required.');
        $pid = DocService::addPayment($db, $iid, [
            'amount' => Format::parseIdr($args['amount'] ?? 0),
            'pay_date' => $args['pay_date'] ?? date('Y-m-d'),
            'method' => $args['method'] ?? 'Transfer',
            'purpose' => $args['purpose'] ?? 'balance',
            'reference' => $args['reference'] ?? null,
            'note' => $args['note'] ?? null,
        ], Auth::actorId());
        return ['invoice_id' => $iid, 'payment_id' => $pid];
    }

    private static function voidPayment(PDO $db, array $args): array
    {
        $pid = (int)($args['payment_id'] ?? 0);
        if ($pid <= 0) throw new RuntimeException('payment_id is required.');
        $st = $db->prepare('SELECT * FROM payments WHERE id=?'); $st->execute([$pid]);
        $pay = $st->fetch();
        if (!$pay) throw new RuntimeException('Payment not found.');
        if ($pay['voided_at']) throw new RuntimeException('Payment already voided.');
        $db->prepare('UPDATE payments SET voided_at=NOW(), void_reason=? WHERE id=?')->execute([$args['reason'] ?? 'void via MCP', $pid]);
        Repo::audit($db, 'invoice', (int)$pay['invoice_id'], 'mcp_payment_void', Auth::actorId(), 'payment:' . $pid);
        return ['payment_id' => $pid, 'invoice_id' => (int)$pay['invoice_id'], 'voided' => true];
    }
}
