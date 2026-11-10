<?php
// public/api/referrals.php — GET. Requires login. Returns the
// CURRENT session user's own direct referrals — people who registered
// with ?ref=<their username>. No user_id parameter accepted from the
// client, same pattern as /api/wallet.php and /api/affiliate/stats.php.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$user = require_login();

$userAdmin = new UserAdminService();
$referrals = $userAdmin->referrals((int)$user['id']);

// Public-safe view: username, joined date, status — never email/phone.
// A deleted referral keeps its place in the count/history, but its
// scrubbed placeholder username is never shown to the referrer.
$safe = array_map(fn($r) => [
    'username'  => $r['status'] === 'deleted' ? 'Deleted account' : $r['username'],
    'status'    => $r['status'],
    'joined_at' => $r['created_at'],
], $referrals);

$config = require __DIR__ . '/../../config/config.php';
$referralLink = rtrim($config['app']['url'], '/') . '/register.html?ref=' . rawurlencode($user['username']);

json_response([
    'success'        => true,
    'referral_link'  => $referralLink,
    'referral_count' => count($safe),
    'referrals'      => $safe,
]);
