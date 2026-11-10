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
            if ($_POST['action'] === 'approve') {
                $walletSvc->releasePending($txId);
                (new AdminLogger())->log((int)$admin['id'], 'transaction.approve', 'transaction', $txId, null, admin_client_ip());
                $flash = ['type' => 'success', 'text' => "Transaction #{$txId} approved and released to available balance."];
            } elseif ($_POST['action'] === 'reject') {
                $walletSvc->rejectPending($txId, (string)($_POST['reason'] ?? 'Rejected by admin'));
                (new AdminLogger())->log((int)$admin['id'], 'transaction.reject', 'transaction', $txId, (string)($_POST['reason'] ?? ''), admin_client_ip());
                $flash = ['type' => 'success', 'text' => "Transaction #{$txId} rejected."];
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not process: ' . $e->getMessage()];
        }
    }
}

$pending = $walletSvc->pendingApprovals(100);

$pageTitle = 'Approvals';
$activeNav = 'transactions';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div><h1>Pending approvals</h1><p>Affiliate commissions, referral bonuses, and activity rewards waiting to move from pending to available balance.</p></div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<div class="card">
  <table class="data">
    <thead><tr><th>User</th><th>Type</th><th>Amount</th><th>Reference</th><th>Description</th><th>Submitted</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pending as $tx): ?>
      <tr>
        <td><a href="/admin/user_detail.php?id=<?= (int)$tx['user_id'] ?>"><?= admin_e($tx['username']) ?></a></td>
        <td><span class="badge pending"><?= admin_e(str_replace('_', ' ', $tx['type'])) ?></span></td>
        <td class="mono">KSh <?= number_format((float)$tx['amount'], 2) ?></td>
        <td class="text-faint mono"><?= admin_e($tx['reference'] ?? '—') ?></td>
        <td><?= admin_e($tx['description'] ?? '—') ?></td>
        <td class="text-faint"><?= admin_e($tx['created_at']) ?></td>
        <td style="white-space:nowrap;">
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
            <input type="hidden" name="tx_id" value="<?= (int)$tx['id'] ?>">
            <button type="submit" name="action" value="approve" class="btn btn-sm btn-primary" onclick="return confirm('Approve and release this to the user\'s available balance?');">Approve</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
            <input type="hidden" name="tx_id" value="<?= (int)$tx['id'] ?>">
            <input type="hidden" name="reason" value="Rejected during review">
            <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger" onclick="return confirm('Reject this transaction? The pending amount will be removed.');">Reject</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($pending)): ?>
        <tr><td colspan="7" class="text-faint">Nothing pending right now.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
