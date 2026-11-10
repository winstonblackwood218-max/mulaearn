<?php
// public/api/me.php — GET. Returns the logged-in user, or 401.
// Also the endpoint the frontend hits first to obtain a CSRF token
// for the register/login forms.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$auth = new Auth();
$user = $auth->currentUser();

json_response([
    'success'    => true,
    'user'       => $user,
    'csrf_token' => Csrf::token(),
]);
