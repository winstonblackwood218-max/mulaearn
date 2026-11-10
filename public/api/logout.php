<?php
// public/api/logout.php — POST csrf_token.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (!Csrf::verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid or expired form.'], 419);
}

(new Auth())->logout();
json_response(['success' => true]);
