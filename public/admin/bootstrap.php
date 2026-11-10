<?php
// public/admin/bootstrap.php — loaded first by every admin page.
// Uses its OWN session cookie name, isolated from the user-facing
// site's session, so a leaked user session cookie can never be
// replayed against the admin panel or vice versa.

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict'); // stricter than the user site — admin never needs cross-site posts
session_name('mulaearn_admin_session');
session_start();

spl_autoload_register(function (string $class) {
    $path = __DIR__ . '/../../src/' . $class . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function admin_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function require_admin(): array
{
    $auth = new AdminAuth();
    $admin = $auth->currentAdmin();
    if (!$admin) {
        header('Location: /admin/login.php');
        exit;
    }
    return $admin;
}

function admin_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
