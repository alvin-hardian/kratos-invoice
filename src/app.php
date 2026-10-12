<?php
declare(strict_types=1);
use App\Middleware\AuthMiddleware;
use App\Services\Calculator;
use App\Services\DocService;
use App\Support\DB;
use App\Support\Format;
use App\Support\Repo;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/helpers.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

$app = AppFactory::create();
$twig = Twig::create(__DIR__ . '/../templates', ['cache' => false, 'autoescape' => 'html']);
$app->add(TwigMiddleware::create($app, $twig));
$app->add(new AuthMiddleware());
$app->addRoutingMiddleware();
$app->addErrorMiddleware((bool)(getenv('APP_DEBUG') ?: true), true, true);

$db = DB::pdo();
$twig->getEnvironment()->addFilter(new \Twig\TwigFilter('idr', fn($n) => Format::idr((int)$n)));
$twig->getEnvironment()->addFilter(new \Twig\TwigFilter('d', fn($v) => Format::escDate($v)));
$twig->getEnvironment()->addFilter(new \Twig\TwigFilter('qty', fn($v, $unit = null) => Format::qty($v, $unit)));
$twig->getEnvironment()->addFilter(new \Twig\TwigFilter('json_decode', fn($v) => json_decode((string)$v, true)));
$twig->getEnvironment()->addFunction(new \Twig\TwigFunction('csrf', fn() => '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') . '">', ['is_safe' => ['html']]));
$twig->getEnvironment()->addFunction(new \Twig\TwigFunction('asset', function (string $p) {
    $file = __DIR__ . '/../public/' . ltrim($p, '/');
    $v = is_file($file) ? substr(md5_file($file), 0, 8) : 'dev';
    return '/' . ltrim($p, '/') . '?v=' . $v;
}));$app->add(function ($req, $h) use ($twig) {
    $twig->getEnvironment()->addGlobal('flash', flashes());
    $twig->getEnvironment()->addGlobal('sess', $_SESSION);
    return $h->handle($req);
});

// ---- Auth ----
$app->get('/login', function ($req, $res) use ($twig) {
    return $twig->render($res, 'login.twig');
});
$app->post('/login', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    $st = $db->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([trim($p['email'] ?? '')]);
    $u = $st->fetch();
    if ($u && password_verify($p['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id']; $_SESSION['uname'] = $u['name'];
        return $res->withHeader('Location', '/')->withStatus(302);
    }
    $_SESSION['flash'] = [['msg' => 'Invalid credentials.', 'type' => 'danger']];
    return $res->withHeader('Location', '/login')->withStatus(302);
});
$app->post('/logout', function ($req, $res) {
    session_destroy();
    return $res->withHeader('Location', '/login')->withStatus(302);
});

// ---- Dashboard ----
$app->get('/', function ($req, $res) use ($db, $twig) {
    $out = $db->query("SELECT COALESCE(SUM(i.total - COALESCE(p.paid,0)),0) AS outstanding FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status='issued' AND i.total > COALESCE(p.paid,0)")->fetch()['outstanding'] ?? 0;
    $over = $db->query("SELECT COALESCE(SUM(i.total - COALESCE(p.paid,0)),0) AS o FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.status='issued' AND i.due_date < CURDATE() AND i.total > COALESCE(p.paid,0)")->fetch()['o'] ?? 0;
    $month = $db->query("SELECT COALESCE(SUM(amount),0) m FROM payments WHERE voided_at IS NULL AND DATE_FORMAT(pay_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch()['m'] ?? 0;
    $await = $db->query("SELECT COUNT(*) c FROM quotations WHERE status='issued'")->fetch()['c'] ?? 0;
    $dpdue = $db->query("SELECT COALESCE(SUM(GREATEST(i.dp_amount - COALESCE(d.dp,0),0)),0) d FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) dp FROM payments WHERE purpose='dp' AND voided_at IS NULL GROUP BY invoice_id) d ON d.invoice_id=i.id WHERE i.status='issued' AND i.dp_type != 'none' AND i.dp_amount > COALESCE(d.dp,0)")->fetch()['d'] ?? 0;
    $recent_q = $db->query("SELECT q.*, c.name customer_name FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id ORDER BY q.id DESC LIMIT 5")->fetchAll();
    $recent_i = $db->query("SELECT i.*, c.name customer_name FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id ORDER BY i.id DESC LIMIT 5")->fetchAll();
    return $twig->render($res, 'dashboard.twig', compact('out','over','month','await','dpdue','recent_q','recent_i'));
});

// ---- Generic list helper ----
function paginate(PDO $db, string $sql, array $params, int $page, int $per): array {
    $count = $db->prepare("SELECT COUNT(*) c FROM ($sql) t");
    $count->execute($params);
    $total = (int)$count->fetch()['c'];
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, $page), $pages);
    $st = $db->prepare($sql . ' LIMIT ' . (($page-1)*$per) . ",$per");
    $st->execute($params);
    return [$st->fetchAll(), $total, $pages, $page];
}

// ---- Customers ----
$app->get('/customers', function ($req, $res) use ($db, $twig) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)'; $par = array_fill(0, 3, '%' . $q['s'] . '%'); }
    if (isset($q['arch']) && $q['arch'] !== '') { $where .= ' AND archived=?'; $par[] = $q['arch']; }
    [$rows, $total, $pages, $page] = paginate($db, "SELECT * FROM customers WHERE $where ORDER BY id DESC", $par, (int)($q['page'] ?? 1), 15);
    return $twig->render($res, 'customers.twig', ['rows' => $rows, 'q' => $q, 'total' => $total, 'pages' => $pages, 'page' => $page]);
});
$app->post('/customers', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) { flash('Invalid session.', 'danger'); return $res->withHeader('Location', '/customers')->withStatus(302); }
    if (trim($p['name'] ?? '') === '') { flash('Name required.', 'danger'); return $res->withHeader('Location', '/customers')->withStatus(302); }
    if (!empty($p['id'])) {
        $db->prepare('UPDATE customers SET name=?, contact_person=?, email=?, phone=?, billing_address=?, tax_id=?, archived=? WHERE id=?')->execute([$p['name'], $p['contact_person'] ?: null, $p['email'] ?: null, $p['phone'] ?: null, $p['billing_address'] ?: null, $p['tax_id'] ?: null, !empty($p['archived']) ? 1 : 0, $p['id']]);
    } else {
        $db->prepare('INSERT INTO customers (name, contact_person, email, phone, billing_address, tax_id) VALUES (?,?,?,?,?,?)')->execute([$p['name'], $p['contact_person'] ?: null, $p['email'] ?: null, $p['phone'] ?: null, $p['billing_address'] ?: null, $p['tax_id'] ?: null]);
    }
    flash('Customer saved.');
    // inline-create from editor returns JSON
    if (($p['ajax'] ?? '') === '1') { $res->getBody()->write(json_encode(['id' => $db->lastInsertId() ?: $p['id']])); return $res->withHeader('Content-Type', 'application/json'); }
    return $res->withHeader('Location', '/customers')->withStatus(302);
});
$app->get('/customers/{id}', function ($req, $res, $a) use ($db, $twig) {
    $st = $db->prepare('SELECT * FROM customers WHERE id=?'); $st->execute([$a['id']]);
    $c = $st->fetch();
    $q = $db->prepare('SELECT * FROM quotations WHERE customer_id=? ORDER BY id DESC'); $q->execute([$a['id']]);
    $i = $db->prepare('SELECT * FROM invoices WHERE customer_id=? ORDER BY id DESC'); $i->execute([$a['id']]);
    return $twig->render($res, 'customer_detail.twig', ['c' => $c, 'quotes' => $q->fetchAll(), 'invoices' => $i->fetchAll()]);
});

// ---- Products ----
$app->get('/products', function ($req, $res) use ($db, $twig) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (p.name LIKE ?)'; $par[] = '%' . $q['s'] . '%'; }
    if (isset($q['active']) && $q['active'] !== '') { $where .= ' AND p.active=?'; $par[] = $q['active']; }
    [$rows, $total, $pages, $page] = paginate($db, "SELECT p.*, t.code tax_code FROM products p LEFT JOIN tax_rates t ON t.id=p.tax_rate_id WHERE $where ORDER BY p.id DESC", $par, (int)($q['page'] ?? 1), 15);
    $taxes = $db->query('SELECT * FROM tax_rates WHERE active=1 ORDER BY rate')->fetchAll();
    return $twig->render($res, 'products.twig', ['rows' => $rows, 'taxes' => $taxes, 'q' => $q, 'total' => $total, 'pages' => $pages, 'page' => $page]);
});
$app->post('/products', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) { flash('Invalid session.', 'danger'); return $res->withHeader('Location', '/products')->withStatus(302); }
    $vals = [$p['name'], $p['description'] ?: null, $p['unit_label'] ?: 'pcs', \App\Support\Format::parseIdr($p['unit_price'] ?? 0), ($p['tax_rate_id'] ?? '') === '' ? null : (int)$p['tax_rate_id'], !empty($p['mult_enabled_default']) ? 1 : 0, $p['mult_label'] ?: null, ($p['mult_value'] ?? '') === '' ? null : \App\Support\Format::parseQty($p['mult_value']), !empty($p['active']) ? 1 : 1];
    if (!empty($p['id'])) {
        $db->prepare('UPDATE products SET name=?, description=?, unit_label=?, unit_price=?, tax_rate_id=?, mult_enabled_default=?, mult_label=?, mult_value=?, active=? WHERE id=?')->execute([...$vals, $p['id']]);
    } else {
        $db->prepare('INSERT INTO products (name, description, unit_label, unit_price, tax_rate_id, mult_enabled_default, mult_label, mult_value, active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($vals);
    }
    flash('Item saved.');
    return $res->withHeader('Location', '/products')->withStatus(302);
});
$app->post('/products/{id}/archive', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/products')->withStatus(302);
    $db->prepare('UPDATE products SET active=1-active WHERE id=?')->execute([$a['id']]);
    flash('Item status updated.');
    return $res->withHeader('Location', '/products')->withStatus(302);
});

// ---- Settings + banks + taxes ----
$app->get('/settings', function ($req, $res) use ($db, $twig) {
    $s = Repo::settings($db);
    $banks = $db->query('SELECT * FROM bank_accounts ORDER BY active DESC, id')->fetchAll();
    $taxes = $db->query('SELECT * FROM tax_rates ORDER BY rate')->fetchAll();
    return $twig->render($res, 'settings.twig', ['s' => $s, 'banks' => $banks, 'taxes' => $taxes]);
});
$app->post('/settings', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) { flash('Invalid session.', 'danger'); return $res->withHeader('Location', '/settings')->withStatus(302); }
    $logo = null;
    // keep existing logo unless new upload
    $cur = Repo::settings($db);
    $logo = $cur['logo_path'] ?? null;
    if (!empty($_FILES['logo']['tmp_name'])) {
        $f = $_FILES['logo'];
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        $mime = mime_content_type($f['tmp_name']);
        if (!isset($allowed[$mime]) || $f['size'] > 2 * 1024 * 1024) {
            flash('Logo must be PNG/JPG/WebP under 2MB.', 'danger');
            return $res->withHeader('Location', '/settings')->withStatus(302);
        }
        $name = 'logo_' . time() . '.' . $allowed[$mime];
        if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../public/uploads/' . $name)) {
            flash('Logo upload failed (storage not writable).', 'danger');
            return $res->withHeader('Location', '/settings')->withStatus(302);
        }
        if ($logo && str_starts_with(str_replace('\\', '/', $logo), 'uploads/')) {
            @unlink(__DIR__ . '/../public/uploads/' . basename($logo));
        }
        $logo = 'uploads/' . $name;
    }
    $db->prepare('UPDATE company_settings SET company_name=?, address=?, email=?, phone=?, tax_id=?, logo_path=?, quote_validity_days=?, invoice_payment_days=?, default_notes=?, default_terms=?, quote_prefix=?, invoice_prefix=? WHERE id=1')
        ->execute([$p['company_name'], $p['address'] ?: null, $p['email'] ?: null, $p['phone'] ?: null, $p['tax_id'] ?: null, $logo, (int)($p['quote_validity_days'] ?? 14), (int)($p['invoice_payment_days'] ?? 14), $p['default_notes'] ?: null, $p['default_terms'] ?: null, $p['quote_prefix'] ?: 'QT', $p['invoice_prefix'] ?: 'INV']);
    flash('Settings saved.');
    return $res->withHeader('Location', '/settings')->withStatus(302);
});
$app->post('/banks', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/settings')->withStatus(302);
    if (!empty($p['id'])) {
        $db->prepare('UPDATE bank_accounts SET bank_name=?, account_number=?, holder=?, branch=?, note=?, is_default_doc=?, active=? WHERE id=?')->execute([$p['bank_name'], $p['account_number'], $p['holder'], $p['branch'] ?: null, $p['note'] ?: null, !empty($p['is_default_doc']) ? 1 : 0, isset($p['active']) ? (int)$p['active'] : 1, $p['id']]);
    } else {
        $db->prepare('INSERT INTO bank_accounts (bank_name, account_number, holder, branch, note, is_default_doc, active) VALUES (?,?,?,?,?,?,1)')->execute([$p['bank_name'], $p['account_number'], $p['holder'], $p['branch'] ?: null, $p['note'] ?: null, !empty($p['is_default_doc']) ? 1 : 0]);
    }
    flash('Bank account saved.');
    return $res->withHeader('Location', '/settings')->withStatus(302);
});
$app->post('/taxes', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/settings')->withStatus(302);
    if (!empty($p['id'])) {
        $db->prepare('UPDATE tax_rates SET code=?, label=?, rate=?, active=? WHERE id=?')->execute([$p['code'], $p['label'], (float)$p['rate'], !empty($p['active']) ? 1 : 0, $p['id']]);
    } else {
        $db->prepare('INSERT INTO tax_rates (code, label, rate, active) VALUES (?,?,?,1)')->execute([$p['code'], $p['label'], (float)$p['rate']]);
    }
    flash('Tax rate saved.');
    return $res->withHeader('Location', '/settings')->withStatus(302);
});

// ---- Quotations ----
$app->get('/quotations', function ($req, $res) use ($db, $twig) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (qq.number LIKE ? OR c.name LIKE ? OR qq.reference LIKE ?)'; $par = array_fill(0, 3, '%' . $q['s'] . '%'); }
    if (!empty($q['status'])) { $where .= ' AND qq.status=?'; $par[] = $q['status']; }
    [$rows, $total, $pages, $page] = paginate($db, "SELECT qq.*, c.name customer_name FROM quotations qq LEFT JOIN customers c ON c.id=qq.customer_id WHERE $where ORDER BY qq.id DESC", $par, (int)($q['page'] ?? 1), 15);
    return $twig->render($res, 'quotations.twig', ['rows' => $rows, 'q' => $q, 'total' => $total, 'pages' => $pages, 'page' => $page]);
});
$app->get('/quotations/new', function ($req, $res) use ($db, $twig) {
    return $twig->render($res, 'editor.twig', editorData($db, 'quote', null));
});
$app->post('/quotations', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) { flash('Invalid session.', 'danger'); return $res->withHeader('Location', '/quotations')->withStatus(302); }
    try {
        $n = DocService::normalizePayload($p, $db);
        if ($errs = Calculator::validateDocument(['customer_id' => $n['customer_id'], 'doc_date' => $n['doc_date'], 'sections' => $n['sections'], 'discount_type' => $n['discount_type'], 'discount_value' => $n['discount_value']])) throw new \RuntimeException(implode(' ', $errs));
        $id = DocService::saveDraft($db, 'quote', null, $n, $_SESSION['uid'] ?? null);
        flash('Quotation draft saved.');
        return $res->withHeader('Location', "/quotations/$id")->withStatus(302);
    } catch (\Throwable $e) {
        flash($e->getMessage(), 'danger');
        return $res->withHeader('Location', '/quotations/new')->withStatus(302);
    }
});
$app->get('/quotations/{id}', function ($req, $res, $a) use ($db, $twig) {
    $d = DocService::load($db, 'quote', (int)$a['id']);
    if (!$d) return $res->withStatus(404);
    $logs = $db->prepare('SELECT * FROM audit_log WHERE entity_type=? AND entity_id=? ORDER BY id DESC'); $logs->execute(['quote', $a['id']]);
    return $twig->render($res, 'quote_detail.twig', ['d' => $d, 'logs' => $logs->fetchAll()]);
});
$app->get('/quotations/{id}/edit', function ($req, $res, $a) use ($db, $twig) {
    $d = DocService::load($db, 'quote', (int)$a['id']);
    if (!$d || $d['status'] !== 'draft') { flash('Only drafts can be edited.', 'danger'); return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302); }
    return $twig->render($res, 'editor.twig', editorData($db, 'quote', $d));
});
$app->post('/quotations/{id}', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
    try {
        $n = DocService::normalizePayload($p, $db);
        DocService::saveDraft($db, 'quote', (int)$a['id'], $n, $_SESSION['uid'] ?? null);
        flash('Draft updated.');
        return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
    } catch (\Throwable $e) {
        flash($e->getMessage(), 'danger');
        return $res->withHeader('Location', '/quotations/' . $a['id'] . '/edit')->withStatus(302);
    }
});
$app->post('/quotations/{id}/issue', function ($req, $res, $a) use ($db) {
    if (!check_csrf((array)$req->getParsedBody())) return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
    try {
        $num = DocService::issue($db, 'quote', (int)$a['id'], $_SESSION['uid'] ?? null);
        flash("Issued as $num.");
    } catch (\Throwable $e) { flash($e->getMessage(), 'danger'); }
    return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
});
$app->post('/quotations/{id}/status', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
    $to = $p['to'] ?? '';
    if (!in_array($to, ['accepted','rejected','cancelled'], true)) { flash('Invalid status.', 'danger'); return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302); }
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT status FROM quotations WHERE id=? FOR UPDATE'); $st->execute([$a['id']]);
        $cur = $st->fetch()['status'] ?? null;
        if ($cur !== 'issued' && !($cur === 'accepted' && $to === 'cancelled')) throw new \RuntimeException('Allowed only from issued (accept/reject/cancel).');
        $db->prepare('UPDATE quotations SET status=?, cancel_reason=? WHERE id=?')->execute([$to, $p['reason'] ?? null, $a['id']]);
        Repo::audit($db, 'quote', (int)$a['id'], $to, $_SESSION['uid'] ?? null, $p['reason'] ?? null);
        $db->commit();
        flash("Quotation $to.");
    } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); flash($e->getMessage(), 'danger'); }
    return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
});
$app->post('/quotations/{id}/duplicate', function ($req, $res, $a) use ($db) {
    if (!check_csrf((array)$req->getParsedBody())) return $res->withHeader('Location', '/quotations')->withStatus(302);
    $d = DocService::load($db, 'quote', (int)$a['id']);
    $db->beginTransaction();
    $db->prepare('INSERT INTO quotations (customer_id, doc_date, expiry_date, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, created_by) SELECT customer_id, CURDATE(), expiry_date, reference, subtotal, discount_type, discount_value, discount_amount, tax_total, tax_breakdown, total, dp_type, dp_value, dp_amount, dp_due_date, notes, terms, ? FROM quotations WHERE id=?')->execute([$_SESSION['uid'] ?? null, $a['id']]);
    $nid = (int)$db->lastInsertId();
    foreach ($d['sections'] as $si => $sec) {
        $db->prepare('INSERT INTO quotation_sections (quotation_id, title, description, sort_order) VALUES (?,?,?,?)')->execute([$nid, $sec['title'], $sec['description'], $si]);
        $ns = (int)$db->lastInsertId();
        foreach ($sec['lines'] as $ord => $l) {
            $db->prepare('INSERT INTO quotation_lines (section_id, product_id, description, quantity, unit_label, unit_price, mult_enabled, mult_label, mult_value, tax_rate_id, tax_rate_snapshot, waived, price_mode, line_amount, tax_amount, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$ns, $l['product_id'], $l['description'], $l['quantity'], $l['unit_label'], $l['unit_price'], $l['mult_enabled'], $l['mult_label'], $l['mult_value'], $l['tax_rate_id'], $l['tax_rate_snapshot'], $l['waived'], $l['price_mode'], $l['line_amount'], $l['tax_amount'], $ord]);
        }
    }
    foreach ($d['banks'] as $i => $b) $db->prepare('INSERT INTO quotation_banks (quotation_id, bank_id, snapshot, sort_order) VALUES (?,?,?,?)')->execute([$nid, $b['bank_id'], $b['snapshot'], $i]);
    $db->commit();
    flash('Duplicated as new draft.');
    return $res->withHeader('Location', "/quotations/$nid/edit")->withStatus(302);
});
$app->post('/quotations/{id}/delete', function ($req, $res, $a) use ($db) {
    if (!check_csrf((array)$req->getParsedBody())) return $res->withHeader('Location', '/quotations')->withStatus(302);
    $db->prepare("DELETE FROM quotations WHERE id=? AND status='draft'")->execute([$a['id']]);
    flash('Draft deleted (if it was a draft).');
    return $res->withHeader('Location', '/quotations')->withStatus(302);
});
$app->post('/quotations/{id}/convert', function ($req, $res, $a) use ($db) {
    if (!check_csrf((array)$req->getParsedBody())) return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302);
    try {
        $iid = DocService::convert($db, (int)$a['id'], $_SESSION['uid'] ?? null);
        flash('Converted. Invoice draft ready.');
        return $res->withHeader('Location', "/invoices/$iid/edit")->withStatus(302);
    } catch (\Throwable $e) { flash($e->getMessage(), 'danger'); return $res->withHeader('Location', '/quotations/' . $a['id'])->withStatus(302); }
});

// ---- Invoices ----
$app->get('/invoices', function ($req, $res) use ($db, $twig) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (i.number LIKE ? OR c.name LIKE ? OR i.reference LIKE ?)'; $par = array_fill(0, 3, '%' . $q['s'] . '%'); }
    if (!empty($q['status'])) { $where .= ' AND i.status=?'; $par[] = $q['status']; }
    [$rows, $total, $pages, $page] = paginate($db, "SELECT i.*, c.name customer_name, COALESCE(p.paid,0) paid FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE $where ORDER BY i.id DESC", $par, (int)($q['page'] ?? 1), 15);
    return $twig->render($res, 'invoices.twig', ['rows' => $rows, 'q' => $q, 'total' => $total, 'pages' => $pages, 'page' => $page]);
});
$app->get('/invoices/new', function ($req, $res) use ($db, $twig) {
    return $twig->render($res, 'editor.twig', editorData($db, 'invoice', null));
});
$app->post('/invoices', function ($req, $res) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/invoices')->withStatus(302);
    try {
        $n = DocService::normalizePayload($p, $db);
        $id = DocService::saveDraft($db, 'invoice', null, $n, $_SESSION['uid'] ?? null);
        flash('Invoice draft saved.');
        return $res->withHeader('Location', "/invoices/$id")->withStatus(302);
    } catch (\Throwable $e) { flash($e->getMessage(), 'danger'); return $res->withHeader('Location', '/invoices/new')->withStatus(302); }
});
$app->get('/invoices/{id}', function ($req, $res, $a) use ($db, $twig) {
    $d = DocService::load($db, 'invoice', (int)$a['id']);
    if (!$d) return $res->withStatus(404);
    $logs = $db->prepare('SELECT * FROM audit_log WHERE entity_type=? AND entity_id=? ORDER BY id DESC'); $logs->execute(['invoice', $a['id']]);
    return $twig->render($res, 'invoice_detail.twig', ['d' => $d, 'logs' => $logs->fetchAll()]);
});
$app->get('/invoices/{id}/edit', function ($req, $res, $a) use ($db, $twig) {
    $d = DocService::load($db, 'invoice', (int)$a['id']);
    if (!$d || $d['status'] !== 'draft') { flash('Only drafts editable.', 'danger'); return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302); }
    return $twig->render($res, 'editor.twig', editorData($db, 'invoice', $d));
});
$app->post('/invoices/{id}', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
    try {
        $n = DocService::normalizePayload($p, $db);
        DocService::saveDraft($db, 'invoice', (int)$a['id'], $n, $_SESSION['uid'] ?? null);
        flash('Draft updated.');
        return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
    } catch (\Throwable $e) { flash($e->getMessage(), 'danger'); return $res->withHeader('Location', '/invoices/' . $a['id'] . '/edit')->withStatus(302); }
});
$app->post('/invoices/{id}/issue', function ($req, $res, $a) use ($db) {
    if (!check_csrf((array)$req->getParsedBody())) return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
    try { $num = DocService::issue($db, 'invoice', (int)$a['id'], $_SESSION['uid'] ?? null); flash("Issued as $num."); }
    catch (\Throwable $e) { flash($e->getMessage(), 'danger'); }
    return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
});
$app->post('/invoices/{id}/cancel', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
    $c = $db->prepare('SELECT COUNT(*) c FROM payments WHERE invoice_id=? AND voided_at IS NULL'); $c->execute([$a['id']]);
    if ($c->fetch()['c'] > 0) { flash('Cannot cancel: active payments exist.', 'danger'); return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302); }
    $db->prepare("UPDATE invoices SET status='cancelled', cancel_reason=? WHERE id=? AND status='issued'")->execute([$p['reason'] ?? null, $a['id']]);
    Repo::audit($db, 'invoice', (int)$a['id'], 'cancel', $_SESSION['uid'] ?? null, $p['reason'] ?? null);
    flash('Invoice cancelled.');
    return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
});
$app->post('/invoices/{id}/payments', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
    try {
        DocService::addPayment($db, (int)$a['id'], ['amount' => \App\Support\Format::parseIdr($p['amount'] ?? 0), 'pay_date' => $p['pay_date'] ?? date('Y-m-d'), 'method' => $p['method'] ?? 'Transfer', 'purpose' => $p['purpose'] ?? 'balance', 'reference' => $p['reference'] ?? null, 'note' => $p['note'] ?? null], $_SESSION['uid'] ?? null);
        flash('Payment recorded.');
    } catch (\Throwable $e) { flash($e->getMessage(), 'danger'); }
    return $res->withHeader('Location', '/invoices/' . $a['id'])->withStatus(302);
});
$app->post('/payments/{pid}/void', function ($req, $res, $a) use ($db) {
    $p = (array)$req->getParsedBody();
    if (!check_csrf($p)) return $res->withHeader('Location', '/invoices')->withStatus(302);
    $st = $db->prepare('SELECT * FROM payments WHERE id=?'); $st->execute([$a['pid']]);
    $pay = $st->fetch();
    if ($pay && !$pay['voided_at']) {
        $db->prepare('UPDATE payments SET voided_at=NOW(), void_reason=? WHERE id=?')->execute([$p['reason'] ?? 'void', $a['pid']]);
        Repo::audit($db, 'invoice', (int)$pay['invoice_id'], 'payment_void', $_SESSION['uid'] ?? null, 'payment:' . $a['pid']);
        flash('Payment voided.');
        return $res->withHeader('Location', '/invoices/' . $pay['invoice_id'])->withStatus(302);
    }
    return $res->withHeader('Location', '/invoices')->withStatus(302);
});

// ---- JSON helpers for editor ----
$app->get('/api/customers', function ($req, $res) use ($db) {
    $s = $req->getQueryParams()['s'] ?? '';
    $st = $db->prepare('SELECT id, name, email FROM customers WHERE archived=0 AND (name LIKE ? OR email LIKE ?) LIMIT 20');
    $st->execute(["%$s%", "%$s%"]);
    $res->getBody()->write(json_encode($st->fetchAll()));
    return $res->withHeader('Content-Type', 'application/json');
});
$app->get('/api/products', function ($req, $res) use ($db) {
    $s = $req->getQueryParams()['s'] ?? '';
    $st = $db->prepare('SELECT p.*, t.rate tax_rate_val FROM products p LEFT JOIN tax_rates t ON t.id=p.tax_rate_id WHERE p.active=1 AND p.name LIKE ? LIMIT 20');
    $st->execute(["%$s%"]);
    $res->getBody()->write(json_encode($st->fetchAll()));
    return $res->withHeader('Content-Type', 'application/json');
});

// ---- JSON grid endpoints (full datasets for Grid.js tables) ----
function gridJson($res, array $rows) {
    $res->getBody()->write(json_encode($rows, JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json');
}
$app->get('/api/grid/quotations', function ($req, $res) use ($db) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (qq.number LIKE ? OR c.name LIKE ? OR qq.reference LIKE ?)'; $par = array_fill(0, 3, '%' . $q['s'] . '%'); }
    if (!empty($q['status'])) { $where .= ' AND qq.status=?'; $par[] = $q['status']; }
    $st = $db->prepare("SELECT qq.id, qq.number, qq.status, qq.doc_date, qq.expiry_date, qq.total, c.name customer_name FROM quotations qq LEFT JOIN customers c ON c.id=qq.customer_id WHERE $where ORDER BY qq.id DESC");
    $st->execute($par);
    $today = date('Y-m-d');
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'number' => $r['number'] ?? '(draft)',
        'customer' => $r['customer_name'] ?? '-', 'date_iso' => $r['doc_date'], 'date' => Format::escDate($r['doc_date']),
        'total_num' => (int)$r['total'], 'total' => Format::idr((int)$r['total']),
        'status' => $r['status'], 'expired' => ($r['status'] === 'issued' && $r['expiry_date'] && $r['expiry_date'] < $today)], $st->fetchAll());
    return gridJson($res, $rows);
});
$app->get('/api/grid/invoices', function ($req, $res) use ($db) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (i.number LIKE ? OR c.name LIKE ? OR i.reference LIKE ?)'; $par = array_fill(0, 3, '%' . $q['s'] . '%'); }
    if (!empty($q['status'])) { $where .= ' AND i.status=?'; $par[] = $q['status']; }
    $st = $db->prepare("SELECT i.id, i.number, i.status, i.doc_date, i.due_date, i.total, c.name customer_name, COALESCE(p.paid,0) paid FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE $where ORDER BY i.id DESC");
    $st->execute($par);
    $today = date('Y-m-d');
    $rows = array_map(function ($r) use ($today) {
        $bal = max(0, (int)$r['total'] - (int)$r['paid']);
        return ['id' => (int)$r['id'], 'number' => $r['number'] ?? '(draft)', 'customer' => $r['customer_name'] ?? '-',
            'date_iso' => $r['doc_date'], 'date' => Format::escDate($r['doc_date']),
            'total_num' => (int)$r['total'], 'total' => Format::idr((int)$r['total']),
            'paid_num' => (int)$r['paid'], 'paid' => Format::idr((int)$r['paid']),
            'bal_num' => $bal, 'balance' => Format::idr($bal),
            'status' => $r['status'], 'overdue' => ($r['status'] === 'issued' && $r['due_date'] && $r['due_date'] < $today && $bal > 0)];
    }, $st->fetchAll());
    return gridJson($res, $rows);
});
$app->get('/api/grid/customers', function ($req, $res) use ($db) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)'; $par = array_fill(0, 3, '%' . $q['s'] . '%'); }
    if (isset($q['arch']) && $q['arch'] !== '') { $where .= ' AND archived=?'; $par[] = $q['arch']; }
    $st = $db->prepare("SELECT id, name, contact_person, email, phone, archived FROM customers WHERE $where ORDER BY id DESC");
    $st->execute($par);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'contact' => $r['contact_person'] ?? '-',
        'email' => $r['email'] ?? '-', 'phone' => $r['phone'] ?? '-', 'status' => $r['archived'] ? 'Archived' : 'Active'], $st->fetchAll());
    return gridJson($res, $rows);
});
$app->get('/api/grid/products', function ($req, $res) use ($db) {
    $q = $req->getQueryParams();
    $where = '1=1'; $par = [];
    if (!empty($q['s'])) { $where .= ' AND (p.name LIKE ?)'; $par[] = '%' . $q['s'] . '%'; }
    if (isset($q['active']) && $q['active'] !== '') { $where .= ' AND p.active=?'; $par[] = $q['active']; }
    $st = $db->prepare("SELECT p.id, p.name, p.unit_label, p.unit_price, p.mult_enabled_default, p.mult_value, p.mult_label, p.active, t.code tax_code FROM products p LEFT JOIN tax_rates t ON t.id=p.tax_rate_id WHERE $where ORDER BY p.id DESC");
    $st->execute($par);
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'unit' => $r['unit_label'],
        'price_num' => (int)$r['unit_price'], 'price' => Format::idr((int)$r['unit_price']),
        'mult' => $r['mult_enabled_default'] ? (Format::qty($r['mult_value']) . ' ' . ($r['mult_label'] ?? '')) : '-',
        'tax' => $r['tax_code'] ?? '-', 'status' => $r['active'] ? 'Active' : 'Archived'], $st->fetchAll());
    return gridJson($res, $rows);
});
$app->get('/api/grid/summary', function ($req, $res) use ($db) {
    $q = $req->getQueryParams();
    $from = $q['from'] ?? date('Y-m-01');
    $to = $q['to'] ?? date('Y-m-t');
    $w = "d.status='issued' AND d.doc_date BETWEEN " . $db->quote($from) . " AND " . $db->quote($to);
    if (!empty($q['customer_id'])) $w .= ' AND d.customer_id=' . (int)$q['customer_id'];
    if (!empty($q['s'])) { $s = str_replace(['%', '_'], '', $q['s']); $w .= " AND (d.number LIKE '%$s%' OR c.name LIKE '%$s%' OR d.reference LIKE '%$s%')"; }
    $rows = $db->query("SELECT d.id, d.number, c.name customer_name, d.doc_date, d.total, d.dp_amount, COALESCE(p.dp,0) dp_paid, COALESCE(p.paid,0) paid FROM invoices d LEFT JOIN customers c ON c.id=d.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid, SUM(CASE WHEN purpose='dp' THEN amount ELSE 0 END) dp FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE $w ORDER BY d.doc_date DESC, d.id DESC")->fetchAll();
    $out = array_map(fn($r) => ['id' => (int)$r['id'], 'number' => $r['number'], 'customer' => $r['customer_name'] ?? '-',
        'date_iso' => $r['doc_date'], 'date' => Format::escDate($r['doc_date']),
        'total_num' => (int)$r['total'], 'total' => Format::idr((int)$r['total']),
        'dp_req_num' => (int)$r['dp_amount'], 'dp_req' => Format::idr((int)$r['dp_amount']),
        'dp_paid_num' => (int)$r['dp_paid'], 'dp_paid' => Format::idr((int)$r['dp_paid']),
        'paid_num' => (int)$r['paid'], 'paid' => Format::idr((int)$r['paid']),
        'bal_num' => max(0, (int)$r['total'] - (int)$r['paid']),
        'balance' => Format::idr(max(0, (int)$r['total'] - (int)$r['paid']))], $rows);
    return gridJson($res, $out);
});

$app->get('/api/grid/customers/{id}', function ($req, $res, $a) use ($db) {
    $st = $db->prepare('SELECT * FROM customers WHERE id=?');
    $st->execute([$a['id']]);
    return gridJson($res, $st->fetch() ?: null);
});
$app->get('/api/grid/products/{id}', function ($req, $res, $a) use ($db) {
    $st = $db->prepare('SELECT * FROM products WHERE id=?');
    $st->execute([$a['id']]);
    return gridJson($res, $st->fetch() ?: null);
});

// ---- Summary ----
$app->get('/summary', function ($req, $res) use ($db, $twig) {
    $q = $req->getQueryParams();
    $from = $q['from'] ?? date('Y-m-01');
    $to = $q['to'] ?? date('Y-m-t');
    $cust = $q['customer_id'] ?? '';
    $qstat = $q['qstatus'] ?? '';
    $istat = $q['istatus'] ?? '';
    $cp = $cust !== '' ? ' AND d.customer_id=' . (int)$cust : '';
    $await = $db->query("SELECT COALESCE(SUM(total),0) v FROM quotations d WHERE status='issued' AND doc_date BETWEEN '$from' AND '$to'$cp" . ($qstat ? " AND status='$qstat'" : ''))->fetch()['v'] ?? 0;
    $acc = $db->query("SELECT COALESCE(SUM(total),0) v FROM quotations d WHERE status='accepted' AND doc_date BETWEEN '$from' AND '$to'$cp")->fetch()['v'] ?? 0;
    $inv = $db->query("SELECT COALESCE(SUM(total),0) v FROM invoices d WHERE status='issued' AND doc_date BETWEEN '$from' AND '$to'$cp" . ($istat ? " AND status='$istat'" : ''))->fetch()['v'] ?? 0;
    $paid = $db->query("SELECT COALESCE(SUM(p.amount),0) v FROM payments p JOIN invoices d ON d.id=p.invoice_id WHERE p.voided_at IS NULL AND p.pay_date BETWEEN '$from' AND '$to' AND d.status='issued'$cp" . ($istat ? " AND d.status='$istat'" : ''))->fetch()['v'] ?? 0;
    $out = $db->query("SELECT COALESCE(SUM(d.total-COALESCE(p.paid,0)),0) v FROM invoices d LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE d.status='issued' AND d.doc_date BETWEEN '$from' AND '$to'$cp")->fetch()['v'] ?? 0;
    $over = $db->query("SELECT COALESCE(SUM(d.total-COALESCE(p.paid,0)),0) v FROM invoices d LEFT JOIN (SELECT invoice_id, SUM(amount) paid FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE d.status='issued' AND d.due_date < CURDATE() AND d.doc_date BETWEEN '$from' AND '$to'$cp")->fetch()['v'] ?? 0;
    $dpReq = $db->query("SELECT COALESCE(SUM(dp_amount),0) v FROM invoices d WHERE status='issued' AND doc_date BETWEEN '$from' AND '$to'$cp")->fetch()['v'] ?? 0;
    $dpGot = $db->query("SELECT COALESCE(SUM(p.amount),0) v FROM payments p JOIN invoices d ON d.id=p.invoice_id WHERE p.voided_at IS NULL AND p.purpose='dp' AND p.pay_date BETWEEN '$from' AND '$to' AND d.status='issued'$cp")->fetch()['v'] ?? 0;
    $page = (int)($q['page'] ?? 1);
    $s = $q['s'] ?? '';
    $w = "d.status='issued' AND d.doc_date BETWEEN '$from' AND '$to'";
    if ($cust !== '') $w .= ' AND d.customer_id=' . (int)$cust;
    if ($s !== '') $w .= " AND (d.number LIKE '%$s%' OR c.name LIKE '%$s%' OR d.reference LIKE '%$s%')";
    [$rows, $total, $pages, $page] = paginate($db, "SELECT d.*, c.name customer_name, COALESCE(p.paid,0) paid, COALESCE(p.dp,0) dp_paid FROM invoices d LEFT JOIN customers c ON c.id=d.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid, SUM(CASE WHEN purpose='dp' THEN amount ELSE 0 END) dp FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE $w ORDER BY d.doc_date DESC, d.id DESC", [], $page, 15);
    $customers = $db->query('SELECT id, name FROM customers WHERE archived=0 ORDER BY name')->fetchAll();
    if (($q['export'] ?? '') === 'csv') {
        $all = $db->query("SELECT d.number, c.name customer, d.doc_date, d.due_date, d.total, d.dp_amount, COALESCE(p.dp,0) dp_paid, COALESCE(p.paid,0) paid, (d.total-COALESCE(p.paid,0)) balance FROM invoices d LEFT JOIN customers c ON c.id=d.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) paid, SUM(CASE WHEN purpose='dp' THEN amount ELSE 0 END) dp FROM payments WHERE voided_at IS NULL GROUP BY invoice_id) p ON p.invoice_id=d.id WHERE $w ORDER BY d.doc_date DESC")->fetchAll();
        $res = $res->withHeader('Content-Type', 'text/csv')->withHeader('Content-Disposition', 'attachment; filename="summary.csv"');
        $out2 = fopen('php://temp', 'r+');
        fputcsv($out2, ['number','customer','date','due','total','dp_req','dp_paid','paid','balance']);
        foreach ($all as $r) fputcsv($out2, $r);
        rewind($out2);
        $res->getBody()->write(stream_get_contents($out2));
        return $res;
    }
    return $twig->render($res, 'summary.twig', ['q' => $q, 'from' => $from, 'to' => $to, 'await' => $await, 'acc' => $acc, 'inv' => $inv, 'paid' => $paid, 'out' => $out, 'over' => $over, 'dpReq' => $dpReq, 'dpGot' => $dpGot, 'dpDue' => max(0, $dpReq - $dpGot), 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page, 'customers' => $customers]);
});

// ---- PDFs ----
$app->get('/quotations/{id}/pdf', function ($req, $res, $a) use ($db, $twig) {
    $d = DocService::load($db, 'quote', (int)$a['id']);
    if (!$d) return $res->withStatus(404);
    $settings = Repo::settings($db);
    $pdf = \App\Services\PdfService::render('pdf/doc.twig', ['d' => $d, 'kind' => 'quote', 'settings' => $settings, 'logo_src' => \App\Support\Logo::dataUri(__DIR__ . '/../public', $settings['logo_path'] ?? null), 'font_dir' => realpath(__DIR__ . '/../public/fonts')], $twig);
    $dl = ($req->getQueryParams()['dl'] ?? '') === '1';
    return pdfResponse($res, $pdf, ($d['number'] ?? 'DRAFT') . '.pdf', $dl);
});
$app->get('/invoices/{id}/pdf', function ($req, $res, $a) use ($db, $twig) {
    $d = DocService::load($db, 'invoice', (int)$a['id']);
    if (!$d) return $res->withStatus(404);
    $settings = Repo::settings($db);
    $pdf = \App\Services\PdfService::render('pdf/doc.twig', ['d' => $d, 'kind' => 'invoice', 'settings' => $settings, 'logo_src' => \App\Support\Logo::dataUri(__DIR__ . '/../public', $settings['logo_path'] ?? null), 'font_dir' => realpath(__DIR__ . '/../public/fonts')], $twig);
    $dl = ($req->getQueryParams()['dl'] ?? '') === '1';
    return pdfResponse($res, $pdf, ($d['number'] ?? 'DRAFT') . '.pdf', $dl);
});

function editorData(PDO $db, string $kind, ?array $doc): array {
    $settings = Repo::settings($db);
    $customers = $db->query('SELECT id, name FROM customers WHERE archived=0 ORDER BY name LIMIT 200')->fetchAll();
    $products = $db->query('SELECT p.*, t.rate tax_rate_val FROM products p LEFT JOIN tax_rates t ON t.id=p.tax_rate_id WHERE p.active=1 ORDER BY p.name LIMIT 200')->fetchAll();
    $banks = $db->query('SELECT * FROM bank_accounts WHERE active=1 ORDER BY id')->fetchAll();
    $taxes = $db->query('SELECT * FROM tax_rates WHERE active=1 ORDER BY rate')->fetchAll();
    $defaultBanks = array_column(array_filter($banks, fn($b) => $b['is_default_doc']), 'id');
    $selBanks = $defaultBanks;
    if ($doc) $selBanks = array_column($doc['banks'], 'bank_id');
    $docDate = $doc['doc_date'] ?? date('Y-m-d');
    $expDef = date('Y-m-d', strtotime($docDate . ' +' . (int)($settings['quote_validity_days'] ?? 14) . ' days'));
    $dueDef = date('Y-m-d', strtotime($docDate . ' +' . (int)($settings['invoice_payment_days'] ?? 14) . ' days'));
    return [
        'kind' => $kind, 'doc' => $doc, 'customers' => $customers, 'products' => $products,
        'banks' => $banks, 'taxes' => $taxes, 'selBanks' => $selBanks, 'settings' => $settings,
        'expDef' => $expDef, 'dueDef' => $dueDef,
    ];
}
function pdfResponse($res, string $pdf, string $name, bool $dl) {
    $res->getBody()->write($pdf);
    return $res->withHeader('Content-Type', 'application/pdf')->withHeader('Content-Disposition', ($dl ? 'attachment' : 'inline') . '; filename="' . $name . '"');
}

// ---- MCP (Streamable HTTP, Bearer token; HTTP-only, no STDIO) ----
$app->get('/mcp', function ($req, $res) use ($db) {
    if (!\App\Mcp\Auth::enabled()) {
        $res->getBody()->write(json_encode(\App\Mcp\McpRouter::error(null, -32000, 'MCP disabled. Set MCP_TOKEN in .env.')));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(503);
    }
    if (!\App\Mcp\Auth::verify($req->getHeaderLine('Authorization'))) {
        $res->getBody()->write(json_encode(\App\Mcp\McpRouter::error(null, -32001, 'Unauthorized: invalid MCP_TOKEN.')));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
    $res->getBody()->write(json_encode([
        'name' => \App\Mcp\McpRouter::SERVER_NAME,
        'version' => \App\Mcp\McpRouter::SERVER_VERSION,
        'protocolVersion' => '2025-06-18',
        'transport' => 'streamable-http',
        'tools' => count(\App\Mcp\ToolRegistry::definitions()),
        'readOnly' => \App\Mcp\Auth::readOnly(),
    ], JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json');
});
$app->post('/mcp', function ($req, $res) use ($db) {
    if (!\App\Mcp\Auth::enabled()) {
        $res->getBody()->write(json_encode(\App\Mcp\McpRouter::error(null, -32000, 'MCP disabled. Set MCP_TOKEN in .env.')));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(503);
    }
    if (!\App\Mcp\Auth::verify($req->getHeaderLine('Authorization'))) {
        $id = null;
        try { $tmp = json_decode((string)$req->getBody(), true); $id = is_array($tmp) ? ($tmp['id'] ?? null) : null; } catch (\Throwable) {}
        $res->getBody()->write(json_encode(\App\Mcp\McpRouter::error($id, -32001, 'Unauthorized: invalid MCP_TOKEN.')));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(401);
    }
    $raw = (string)$req->getBody();
    if (trim($raw) === '') {
        $res->getBody()->write(json_encode(\App\Mcp\McpRouter::error(null, -32700, 'Parse error: empty body.')));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $res->getBody()->write(json_encode(\App\Mcp\McpRouter::error(null, -32700, 'Parse error: ' . json_last_error_msg())));
        return $res->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
    [$status, $payload] = \App\Mcp\McpRouter::dispatch($decoded, $db);
    if ($payload !== null) $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
});

return $app;
