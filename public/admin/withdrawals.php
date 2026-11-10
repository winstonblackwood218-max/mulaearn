<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$walletSvc = new WalletService();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Form expired, please retry.'];
    } else {
        $txId = (int)($_POST['tx_id'] ?? 0);
        try {
            if ($_POST['action'] === 'complete') {
                $reference = trim((string)($_POST['reference'] ?? ''));
                if ($reference === '') {
                    throw new RuntimeException('A payment provider reference is required to mark a withdrawal complete.');
                }
                $walletSvc->completeWithdrawal($txId, $reference);
                (new AdminLogger())->log((int)$admin['id'], 'withdrawal.complete', 'transaction', $txId, "ref: {$reference}", admin_client_ip());
                $flash = ['type' => 'success', 'text' => "Withdrawal #{$txId} marked completed."];
            } elseif ($_POST['action'] === 'fail') {
                $reason = trim((string)($_POST['reason'] ?? 'Payment failed'));
                $walletSvc->failWithdrawal($txId, $reason);
                (new AdminLogger())->log((int)$admin['id'], 'withdrawal.fail', 'transaction', $txId, $reason, admin_client_ip());
                $flash = ['type' => 'success', 'text' => "Withdrawal #{$txId} marked failed and funds refunded to the user's available balance."];
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not process: ' . $e->getMessage()];
        }
    }
}

$pending = $walletSvc->pendingWithdrawals(100);

$pageTitle = 'Withdrawals';
$activeNav = 'withdrawals';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Pending withdrawals</h1>
    <p>Only mark a withdrawal complete once you have a real confirmation from the payment provider — this is a manual step until a payment API is integrated.</p>
  </div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<div class="card">
  <table class="data">
    <thead><tr><th>User</th><th>Amount</th><th>Requested</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pending as $tx): ?>
      <tr>
        <td><a href="/admin/user_detail.php?id=<?= (int)$tx['user_id'] ?>"><?= admin_e($tx['username']) ?></a></td>
        <td class="mono">KSh <?= number_format(abs((float)$tx['amount']), 2) ?></td>
        <td class="text-faint"><?= admin_e($tx['created_at']) ?></td>
        <td>
          <details>
            <summary class="btn btn-sm btn-primary" style="cursor:pointer; display:inline-flex;">Mark completed</summary>
            <form method="post" style="margin-top:10px; max-width:280px;">
              <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
              <input type="hidden" name="tx_id" value="<?= (int)$tx['id'] ?>">
              <div class="field">
                <label>Payment provider reference</label>
                <input type="text" name="reference" placeholder="e.g. M-Pesa confirmation code" required>
                <div class="hint">Required — proof of the real payout, not a guess.</div>
              </div>
              <button type="submit" name="action" value="complete" class="btn btn-sm btn-primary">Confirm completed</button>
            </form>
          </details>
          <form method="post" style="display:inline; margin-left:6px;">
            <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
            <input type="hidden" name="tx_id" value="<?= (int)$tx['id'] ?>">
            <input type="hidden" name="reason" value="Payment provider declined">
            <button type="submit" name="action" value="fail" class="btn btn-sm btn-danger" onclick="return confirm('Mark failed and refund the user\'s available balance?');">Mark failed</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
        <tr><td colspan="4" class="text-faint">No pending withdrawals.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
