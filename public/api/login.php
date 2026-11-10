<?php
// public/api/login.php — POST identifier (email or username), password, csrf_token.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (!Csrf::verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid or expired form. Refresh and try again.'], 419);
}

$identifier = (string)($input['identifier'] ?? '');
$password   = (string)($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    json_response(['success' => false, 'error' => 'Enter your email/username and password.'], 422);
}

$auth = new Auth();
$result = $auth->login($identifier, $password, client_ip());

if (!$result['success']) {
    json_response(['success' => false, 'error' => $result['error']], 401);
}

json_response(['success' => true, 'user' => $auth->currentUser()]);
