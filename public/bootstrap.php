<?php
// public/bootstrap.php — loaded first by every entry point.
// Secure session config MUST happen before session_start().

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
// cookie_secure should be '1' behind HTTPS in production; left off here
// so the plain-HTTP local dev server still works.
session_name($config['session']['name']);
session_start();

spl_autoload_register(function (string $class) {
    $path = __DIR__ . '/../src/' . $class . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function require_login(): array
{
    $auth = new Auth();
    $user = $auth->currentUser();
    if (!$user) {
        json_response(['success' => false, 'error' => 'Not authenticated.'], 401);
    }
    return $user;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
