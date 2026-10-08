<?php
declare(strict_types=1);
use App\Support\DB;
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
$app = require __DIR__ . '/../src/app.php';
$app->run();
