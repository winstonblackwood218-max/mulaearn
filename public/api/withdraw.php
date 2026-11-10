<?php
// public/api/withdraw.php — POST amount, csrf_token. Requires login.
//
// Flow: validate -> check account not suspended -> check withdrawal
// not specifically suspended -> check minimum amount (admin-configurable
// setting, never hard-coded) -> WalletService::requestWithdrawal() ->
// email the user's REGISTERED address (never a client-supplied one)
// with the amount requested.
//
// The email is a notification, not a confirmation of payment — the
// withdrawal is still 'pending' until an admin (or, once integrated,
// M-Pesa's callback) marks it completed on /admin/withdrawals.php.

declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$user = require_login();
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (!Csrf::verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid or expired form. Refresh and try again.'], 419);
}

if ($user['status'] !== 'active') {
    json_response(['success' => false, 'error' => 'Your account is not active. Contact support.'], 403);
}

$db = Database::connection();
$stmt = $db->prepare('SELECT withdrawal_suspended FROM users WHERE id = :id');
$stmt->execute([':id' => $user['id']]);
if ((int)$stmt->fetchColumn() === 1) {
    json_response(['success' => false, 'error' => 'Withdrawals are currently suspended on your account. Contact support.'], 403);
}

$amount = $input['amount'] ?? null;
if (!is_numeric($amount) || (float)$amount <= 0) {
    json_response(['success' => false, 'error' => 'Enter a valid withdrawal amount.'], 422);
}
$amount = number_format((float)$amount, 2, '.', '');

$settings = new SettingsService();
$minWithdrawal = (float)$settings->get('min_withdrawal_amount', '0');
if ((float)$amount < $minWithdrawal) {
    json_response(['success' => false, 'error' => "Minimum withdrawal is KSh " . number_format($minWithdrawal, 2) . "."], 422);
}

$wallet = new WalletService();
try {
    $txId = $wallet->requestWithdrawal((int)$user['id'], $amount);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 422);
}

// Notification, not confirmation — see file header.
$mailResult = (new Mailer())->send(
    $user['email'],
    $user['username'],
    'Withdrawal request received',
    "Hi {$user['username']},\n\n"
    . "We've received your request to withdraw KSh {$amount} from your MulaEarn balance.\n\n"
    . "Reference: transaction #{$txId}\n\n"
    . "This request is now pending review. You'll be notified once it's processed. "
    . "If you did not make this request, please contact support immediately.\n\n"
    . "— MulaEarn"
);

json_response([
    'success'        => true,
    'transaction_id' => $txId,
    'email_sent'      => $mailResult['sent'],
]);
