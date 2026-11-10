<?php
// public/api/wallet.php — GET. Requires login. Returns balance +
// recent transactions for the CURRENT session user only — there is
// no user_id parameter accepted from the client, deliberately, so
// there's no way to request someone else's wallet.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$user = require_login(); // exits with 401 if not logged in

$wallet = new WalletService();

json_response([
    'success'      => true,
    'balance'      => $wallet->summaryFor((int)$user['id']),
    'transactions' => $wallet->recentTransactions((int)$user['id']),
]);
