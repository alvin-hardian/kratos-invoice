<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
}
function check_csrf(array $data): bool {
    return isset($data['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$data['csrf']);
}
function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}
function flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
