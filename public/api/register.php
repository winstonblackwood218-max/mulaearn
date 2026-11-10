<?php
// public/api/register.php — POST username, email, phone, country_code,
// password, confirm_password, ref (optional), csrf_token.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (!Csrf::verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid or expired form. Refresh and try again.'], 419);
}

$auth = new Auth();
$result = $auth->register($input);

if (!$result['success']) {
    json_response(['success' => false, 'errors' => $result['errors']], 422);
}

// Auto-login after successful registration.
$auth->login($input['email'] ?? '', $input['password'] ?? '', client_ip());

json_response(['success' => true, 'user_id' => $result['user_id']]);
