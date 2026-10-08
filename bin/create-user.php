<?php
declare(strict_types=1);
// Usage: php bin/create-user.php "Name" email password
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
use App\Support\DB;
[$s, $name, $email, $pass] = [$argv[0] ?? '', $argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if (!$name || !$email || !$pass) { fwrite(STDERR, "Usage: php bin/create-user.php \"Name\" email password\n"); exit(1); }
$pdo = DB::pdo();
$st = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash)');
$st->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
echo "User $email ready.\n";
