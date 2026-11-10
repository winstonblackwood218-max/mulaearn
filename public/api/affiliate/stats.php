<?php
// public/api/affiliate/stats.php — GET. Requires login. Returns the
// CURRENT session user's own affiliate performance — no user_id
// parameter accepted, same pattern as /api/wallet.php.

declare(strict_types=1);
require __DIR__ . '/../../bootstrap.php';

$user = require_login();
$affiliate = new AffiliateService();

json_response([
    'success' => true,
    'stats'   => $affiliate->statsForUser((int)$user['id']),
    'links'   => $affiliate->userLinks((int)$user['id']),
]);
