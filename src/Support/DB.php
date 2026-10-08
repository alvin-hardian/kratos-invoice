<?php
declare(strict_types=1);
namespace App\Support;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;

    public static function env(string $k, string $def = ''): string
    {
        $v = getenv($k);
        if ($v !== false && $v !== '') return $v;
        if (isset($_ENV[$k]) && $_ENV[$k] !== '') return (string)$_ENV[$k];
        if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return (string)$_SERVER[$k];
        return $def;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $host = self::env('DB_HOST', '127.0.0.1');
        $port = self::env('DB_PORT', '3306');
        $name = self::env('DB_NAME', 'kratos_invoice');
        $user = self::env('DB_USER', 'root');
        $pass = self::env('DB_PASS', 'root');
        // allow first run without DB selected (migrate creates it)
        $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");
        self::$pdo = $pdo;
        return $pdo;
    }
}
