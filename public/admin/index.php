<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$userSvc = new UserAdminService();
$walletSvc = new WalletService();

$userCounts = $userSvc->counts();
$pendingApprovals = $walletSvc->pendingApprovals(5);
$pendingWithdrawals = $walletSvc->pendingWithdrawals(5);

$db = Database::connection();
$pendingApprovalTotal = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='pending' AND type != 'withdrawal'")->fetchColumn();
$pendingWithdrawalTotal = (float)$db->query("SELECT COALESCE(SUM(-amount),0) FROM transactions WHERE status='pending' AND type = 'withdrawal'")->fetchColumn();
$completedTotal = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='completed' AND amount > 0")->fetchColumn();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div>
    <h1>Dashboard</h1>
    <p>Live figures from the database — nothing here is placeholder data.</p>
  </div>
</div>

<div class="stat-row">
  <div class="stat-box"><b><?= $userCounts['total'] ?></b><span>Total users</span></div>
  <div class="stat-box"><b><?= $userCounts['active'] ?></b><span>Active users</span></div>
  <div class="stat-box"><b><?= $userCounts['newThisWeek'] ?></b><span>New this week</span></div>
  <div class="stat-box"><b><?= $userCounts['suspended'] ?></b><span>Suspended</span></div>
</div>
<div class="stat-row">
  <div class="stat-box"><b class="mono">KSh <?= number_format($pendingApprovalTotal, 2) ?></b><span>Pending approval</span></div>
  <div class="stat-box"><b class="mono">KSh <?= number_format($pendingWithdrawalTotal, 2) ?></b><span>Pending withdrawals</span></div>
  <div class="stat-box"><b class="mono">KSh <?= number_format($completedTotal, 2) ?></b><span>Total paid out (all time)</span></div>
  <div class="stat-box"><b><?= count($pendingApprovals) + count($pendingWithdrawals) ?></b><span>Items awaiting you</span></div>
</div>

<div class="card">
  <h2>Awaiting approval</h2>
  <?php if (empty($pendingApprovals)): ?>
    <p class="text-faint">Nothing pending right now.</p>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>User</th><th>Type</th><th>Amount</th><th>Description</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendingApprovals as $tx): ?>
        <tr>
          <td><?= admin_e($tx['username']) ?></td>
          <td><?= admin_e(str_replace('_', ' ', $tx['type'])) ?></td>
          <td class="mono">KSh <?= number_format((float)$tx['amount'], 2) ?></td>
          <td><?= admin_e($tx['description'] ?? '—') ?></td>
          <td class="text-faint"><?= admin_e($tx['created_at']) ?></td>
          <td><a href="/admin/transactions.php" class="btn btn-sm">Review →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Pending withdrawals</h2>
  <?php if (empty($pendingWithdrawals)): ?>
    <p class="text-faint">Nothing pending right now.</p>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>User</th><th>Amount</th><th>Requested</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendingWithdrawals as $tx): ?>
        <tr>
          <td><?= admin_e($tx['username']) ?></td>
          <td class="mono">KSh <?= number_format(abs((float)$tx['amount']), 2) ?></td>
          <td class="text-faint"><?= admin_e($tx['created_at']) ?></td>
          <td><a href="/admin/withdrawals.php" class="btn btn-sm">Review →</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
