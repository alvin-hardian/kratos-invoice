<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
use App\Services\Calculator;
use App\Services\DocService;
use App\Support\DB;

$pdo = DB::pdo();
$ok = fn($c, $m) => print(($c ? "PASS" : "FAIL") . " $m\n") or exit(1);
// build payload: 2 sections, mixed lines incl. waived + decimal mult
$cid = (int)$pdo->query("SELECT id FROM customers LIMIT 1")->fetch()['id'];
$pdo->prepare("UPDATE customers SET name='E2E Customer' WHERE id=?")->execute([$cid]);
$origName = 'E2E Customer';
$bank = (int)$pdo->query("SELECT id FROM bank_accounts LIMIT 1")->fetch()['id'];
$payload = [
    'customer_id' => $cid, 'doc_date' => date('Y-m-d'), 'expiry_date' => date('Y-m-d', strtotime('+14 days')),
    'reference' => 'E2E', 'discount_type' => 'percent', 'discount_value' => 10,
    'dp_type' => 'percent', 'dp_value' => 30, 'dp_due_date' => date('Y-m-d', strtotime('+7 days')),
    'notes' => 'n', 'terms' => 't', 'bank_ids' => [$bank, 9999],
    'sections' => [
        ['title' => 'Rental', 'description' => '', 'lines' => [
            ['description' => 'Laptop rental', 'quantity' => 10, 'unit_label' => 'units', 'unit_price' => 150000, 'mult_enabled' => 1, 'mult_label' => 'days', 'mult_value' => 1.5, 'tax_rate_id' => null, 'tax_rate' => 12, 'waived' => 0, 'price_mode' => 'normal', 'product_id' => null],
            ['description' => 'Setup (waived)', 'quantity' => 1, 'unit_label' => 'package', 'unit_price' => 5000000, 'mult_enabled' => 0, 'tax_rate' => 0, 'waived' => 1, 'price_mode' => 'compliment', 'product_id' => null],
        ]],
        ['title' => 'Staff', 'description' => '', 'lines' => [
            ['description' => 'Technician', 'quantity' => 2, 'unit_label' => 'persons', 'unit_price' => 750000, 'mult_enabled' => 1, 'mult_label' => 'days', 'mult_value' => 2, 'tax_rate' => 11, 'waived' => 0, 'price_mode' => 'normal', 'product_id' => null],
        ]],
    ],
];
$n = DocService::normalizePayload(array_merge($payload, ['discount_value' => 10, 'dp_value' => 30]), $pdo);
// normalizePayload expects raw post; patch numeric fields already parsed? it parses again - fine for ints
$errs = Calculator::validateDocument(['customer_id' => $n['customer_id'], 'doc_date' => $n['doc_date'], 'sections' => $n['sections'], 'discount_type' => 'percent', 'discount_value' => 10]);
if ($errs) { echo "FAIL validation: " . implode(';', $errs) . "\n"; exit(1); }
echo "PASS validation\n";
$qid = DocService::saveDraft($pdo, 'quote', null, $n, 1);
echo "PASS draft q=$qid subtotal={$n['calc']['subtotal']} total={$n['calc']['total']} dp={$n['calc']['dp_amount']}\n";
// expected: line1 = 10*1.5*150000=2250000, line3=2*2*750000=3000000, subtotal=5250000
if ($n['calc']['subtotal'] !== 5250000) { echo "FAIL subtotal\n"; exit(1); }
$num = DocService::issue($pdo, 'quote', $qid, 1);
echo "PASS issued $num\n";
$pdo->prepare("UPDATE quotations SET status='accepted' WHERE id=?")->execute([$qid]);
$iid = DocService::convert($pdo, $qid, 1);
echo "PASS converted invoice=$iid\n";
$iid2 = DocService::convert($pdo, $qid, 1);
if ($iid2 !== $iid) { echo "FAIL duplicate conversion\n"; exit(1); }
echo "PASS duplicate-convert returns existing\n";
$inum = DocService::issue($pdo, 'invoice', $iid, 1);
echo "PASS invoice issued $inum\n";
$inv = DocService::load($pdo, 'invoice', $iid);
$dpReq = (int)$inv['dp_amount'];
// partial dp
DocService::addPayment($pdo, $iid, ['amount' => (int)($dpReq / 2), 'pay_date' => date('Y-m-d'), 'method' => 'Transfer', 'purpose' => 'dp', 'reference' => null, 'note' => null], 1);
echo "PASS partial dp\n";
try {
    DocService::addPayment($pdo, $iid, ['amount' => $dpReq, 'pay_date' => date('Y-m-d'), 'method' => 'Transfer', 'purpose' => 'dp', 'reference' => null, 'note' => null], 1);
    echo "FAIL dp overpay allowed\n"; exit(1);
} catch (Throwable $e) { echo "PASS dp-overpay blocked\n"; }
try {
    DocService::addPayment($pdo, $iid, ['amount' => $inv['total'] * 2, 'pay_date' => date('Y-m-d'), 'method' => 'Transfer', 'purpose' => 'balance', 'reference' => null, 'note' => null], 1);
    echo "FAIL overpay allowed\n"; exit(1);
} catch (Throwable $e) { echo "PASS overpay blocked\n"; }
// pay rest as balance
$inv2 = DocService::load($pdo, 'invoice', $iid);
DocService::addPayment($pdo, $iid, ['amount' => $inv2['balance'], 'pay_date' => date('Y-m-d'), 'method' => 'Transfer', 'purpose' => 'balance', 'reference' => null, 'note' => null], 1);
$inv3 = DocService::load($pdo, 'invoice', $iid);
if ($inv3['balance'] !== 0) { echo "FAIL balance not zero\n"; exit(1); }
echo "PASS full payment, no double-deduct (paid={$inv3['paid']} total={$inv3['total']})\n";
// snapshot check: change customer name + bank, issued docs keep snapshots
$pdo->prepare("UPDATE customers SET name='CHANGED-AFTER-ISSUE' WHERE id=?")->execute([$cid]);
$q = DocService::load($pdo, 'quote', $qid);
$snap = json_decode($q['customer_snapshot'], true);
if (($snap['name'] ?? '') !== $origName) { echo "FAIL snapshot not frozen\n"; exit(1); }
echo "PASS snapshots frozen\n";
// PDF render
$twig = \Slim\Views\Twig::create(__DIR__ . '/../templates', ['cache' => false]);
\App\Support\TwigFactory::register($twig->getEnvironment());
$pdf = \App\Services\PdfService::render('pdf/doc.twig', ['d' => $inv3, 'kind' => 'invoice', 'settings' => \App\Support\Repo::settings($pdo), 'font_dir' => realpath(__DIR__ . '/../public/fonts')], $twig);
if (strlen($pdf) < 1000 || substr($pdf, 0, 4) !== '%PDF') { echo "FAIL pdf\n"; exit(1); }
file_put_contents(__DIR__ . '/e2e_sample.pdf', $pdf);
echo "PASS pdf " . strlen($pdf) . " bytes\n";
echo "ALL E2E PASS\n";
