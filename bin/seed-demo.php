<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
use App\Support\DB;
$pdo = DB::pdo();
// Demo data (clearly separated, optional)
$pdo->exec("UPDATE company_settings SET company_name='PT Contoh Maju', address='Jl. Merdeka No.1, Jakarta', email='admin@contoh.id', phone='021-1234567', default_notes='Terima kasih.', default_terms='Pembayaran maksimal 14 hari.' WHERE id=1");
$pdo->prepare('INSERT IGNORE INTO customers (name, contact_person, email, phone, billing_address) VALUES (?,?,?,?,?)')->execute(['PT Demo Klien', 'Budi', 'budi@demo.id', '0811', 'Jl. Demo No. 9']);
$pdo->prepare('INSERT IGNORE INTO products (name, description, unit_label, unit_price, mult_enabled_default, mult_label, mult_value) VALUES (?,?,?,?,?,?,?)')->execute(['Laptop rental', 'Unit laptop', 'units', 150000, 1, 'days', 3]);
$pdo->prepare('INSERT IGNORE INTO bank_accounts (bank_name, account_number, holder, is_default_doc) VALUES (?,?,?,1)')->execute(['BCA', '0012345678', 'PT Contoh Maju']);
echo "Demo data loaded.\n";
