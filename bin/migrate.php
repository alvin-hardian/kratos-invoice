<?php
declare(strict_types=1);
// First-deploy seed import: php bin/migrate.php (idempotent, safe to re-run)
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
use App\Support\DB;
$pdo = DB::pdo();
$file = __DIR__ . '/../database/db_seed.sql';
echo "Applying $file\n";
$stmts = array_filter(array_map('trim', preg_split('/;\s*\n/', file_get_contents($file))));
foreach ($stmts as $sql) {
    if ($sql === '') continue;
    $pdo->exec($sql);
}
// Idempotent schema catch-up for databases created before a column existed.
try {
    $has = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'show_signature'")->fetch();
    if (!$has) {
        echo "Adding invoices.show_signature\n";
        $pdo->exec('ALTER TABLE invoices ADD COLUMN show_signature TINYINT(1) NOT NULL DEFAULT 0');
    }
} catch (\Throwable $e) {
    echo 'Schema check skipped: ' . $e->getMessage() . "\n";
}
// 'rejected' quotation status removed: fold legacy rows into cancelled, then shrink ENUM.
try {
    $col = $pdo->query("SHOW COLUMNS FROM quotations LIKE 'status'")->fetch();
    if ($col && str_contains((string)($col['Type'] ?? ''), "'rejected'")) {
        echo "Migrating quotations.status: rejected -> cancelled\n";
        $pdo->exec("UPDATE quotations SET status='cancelled' WHERE status='rejected'");
        $pdo->exec("ALTER TABLE quotations MODIFY status ENUM('draft','issued','accepted','cancelled') NOT NULL DEFAULT 'draft'");
    }
} catch (\Throwable $e) {
    echo 'Quotation status migration skipped: ' . $e->getMessage() . "\n";
}
echo "Done.\n";
