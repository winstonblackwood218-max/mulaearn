<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$userId = (int)($_GET['id'] ?? 0);
$userSvc = new UserAdminService();
$walletSvc = new WalletService();

$user = $userSvc->findWithWallet($userId);
if (!$user) {
    header('Location: /admin/users.php');
    exit;
}

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Form expired, please retry.'];
    } else {
        $action = (string)$_POST['action'];
        try {
            switch ($action) {
                case 'suspend':
                case 'activate':
                    $newStatus = $action === 'suspend' ? 'suspended' : 'active';
                    $userSvc->setStatus($userId, $newStatus);
                    (new AdminLogger())->log((int)$admin['id'], 'user.' . $action, 'user', $userId, null, admin_client_ip());
                    $flash = ['type' => 'success', 'text' => "User set to {$newStatus}."];
                    break;

                case 'suspend_withdrawal':
                case 'unsuspend_withdrawal':
                    $suspend = $action === 'suspend_withdrawal';
                    $userSvc->setWithdrawalSuspension($userId, $suspend);
                    (new AdminLogger())->log((int)$admin['id'], 'user.' . $action, 'user', $userId, null, admin_client_ip());
                    $flash = ['type' => 'success', 'text' => $suspend ? 'Withdrawals suspended for this user.' : 'Withdrawals re-enabled for this user.'];
                    break;

                case 'update_identity':
                    $newUsername = trim((string)($_POST['username'] ?? ''));
                    $newEmail = trim((string)($_POST['email'] ?? ''));
                    $userSvc->updateIdentity($userId, $newUsername, $newEmail);
                    (new AdminLogger())->log((int)$admin['id'], 'user.update_identity', 'user', $userId, "username={$newUsername}, email={$newEmail}", admin_client_ip());
                    $flash = ['type' => 'success', 'text' => 'Username/email updated.'];
                    break;

                case 'adjust_balance':
                    $delta = (string)($_POST['delta'] ?? '0');
                    $reason = trim((string)($_POST['reason'] ?? ''));
                    $txId = $walletSvc->adminAdjustBalance($userId, $delta, $reason);
                    (new AdminLogger())->log((int)$admin['id'], 'wallet.adjust', 'transaction', $txId, "delta={$delta}; reason={$reason}", admin_client_ip());
                    $flash = ['type' => 'success', 'text' => "Balance adjusted (transaction #{$txId})."];
                    break;

                case 'delete_account':
                    $force = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';
                    $userSvc->deleteAccount($userId, $force);
                    (new AdminLogger())->log((int)$admin['id'], 'user.delete', 'user', $userId, $force ? 'forced (nonzero balance)' : null, admin_client_ip());
                    $flash = ['type' => 'success', 'text' => 'Account deleted (anonymized). Transaction history preserved for audit.'];
                    break;

                default:
                    $flash = ['type' => 'error', 'text' => 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not complete action: ' . $e->getMessage()];
        }
        $user = $userSvc->findWithWallet($userId);
    }
}

$referrals = $userSvc->referrals($userId);
$transactions = $walletSvc->recentTransactions($userId, 25);
$isDeleted = $user['status'] === 'deleted';

$pageTitle = $user['username'];
$activeNav = 'users';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div>
    <h1><?= admin_e($user['username']) ?> <span class="badge <?= admin_e($user['status']) ?>"><?= admin_e($user['status']) ?></span>
      <?php if ((int)$user['withdrawal_suspended'] === 1): ?><span class="badge pending">withdrawals suspended</span><?php endif; ?>
    </h1>
    <p><?= admin_e($user['email']) ?> · <?= admin_e($user['country_code']) ?> · Joined <?= admin_e(substr($user['created_at'], 0, 10)) ?></p>
  </div>
  <?php if (!$isDeleted): ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
    <?php if ($user['status'] === 'active'): ?>
      <button type="submit" name="action" value="suspend" class="btn btn-danger" onclick="return confirm('Suspend this user?');">Suspend user</button>
    <?php else: ?>
      <button type="submit" name="action" value="activate" class="btn btn-primary">Reactivate user</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<div class="stat-row">
  <div class="stat-box"><b class="mono">KSh <?= number_format((float)($user['available_balance'] ?? 0), 2) ?></b><span>Available</span></div>
  <div class="stat-box"><b class="mono">KSh <?= number_format((float)($user['pending_balance'] ?? 0), 2) ?></b><span>Pending</span></div>
  <div class="stat-box"><b class="mono">KSh <?= number_format((float)($user['lifetime_earnings'] ?? 0), 2) ?></b><span>Lifetime earnings</span></div>
  <div class="stat-box"><b><?= admin_e($user['sponsor_username'] ?? '—') ?></b><span>Sponsor</span></div>
</div>

<?php if (!$isDeleted): ?>

<div class="card">
  <h2>Withdrawal access</h2>
  <p class="text-faint" style="margin-bottom:12px;">Blocks this user from requesting new withdrawals without suspending their whole account. Existing pending withdrawals are unaffected.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
    <?php if ((int)$user['withdrawal_suspended'] === 1): ?>
      <button type="submit" name="action" value="unsuspend_withdrawal" class="btn btn-primary">Re-enable withdrawals</button>
    <?php else: ?>
      <button type="submit" name="action" value="suspend_withdrawal" class="btn btn-danger" onclick="return confirm('Block this user from requesting withdrawals?');">Suspend withdrawals</button>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h2>Edit username / email</h2>
  <form method="post" style="display:grid; grid-template-columns:1fr 1fr auto; gap:14px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
    <input type="hidden" name="action" value="update_identity">
    <div class="field"><label>Username</label><input type="text" name="username" value="<?= admin_e($user['username']) ?>" required></div>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= admin_e($user['email']) ?>" required></div>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>

<div class="card">
  <h2>Adjust balance</h2>
  <p class="text-faint" style="margin-bottom:12px;">Direct, immediate correction to available balance. Positive or negative. A reason is required and permanently recorded on the transaction and in the audit log.</p>
  <form method="post" style="display:grid; grid-template-columns:160px 1fr auto; gap:14px; align-items:end;">
    <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
    <input type="hidden" name="action" value="adjust_balance">
    <div class="field"><label>Amount (+/-)</label><input type="number" step="0.01" name="delta" placeholder="e.g. 500 or -200" required></div>
    <div class="field"><label>Reason (required)</label><input type="text" name="reason" placeholder="e.g. Compensation for delayed payout" required></div>
    <button type="submit" class="btn btn-primary" onclick="return confirm('Apply this balance adjustment? This is immediate and logged.');">Apply</button>
  </form>
</div>

<div class="card" style="border-color:#E0554A;">
  <h2 style="color:#E0554A;">Delete account</h2>
  <p class="text-faint" style="margin-bottom:12px;">
    This does not run a destructive database delete — it blocks login and permanently scrubs the username, email, and phone,
    while keeping wallet and transaction history intact for the financial audit trail. This cannot be undone.
    <?php if ((float)($user['available_balance'] ?? 0) > 0): ?>
      <br><b>This account has an available balance of KSh <?= number_format((float)$user['available_balance'], 2) ?>.</b>
      Deleting will strand those funds unless you check "delete anyway" below.
    <?php endif; ?>
  </p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
    <input type="hidden" name="action" value="delete_account">
    <?php if ((float)($user['available_balance'] ?? 0) > 0): ?>
      <label style="display:flex; gap:8px; align-items:center; margin-bottom:12px; font-size:13.5px;">
        <input type="checkbox" name="force_delete" value="1" required> I understand this will strand the user's remaining balance.
      </label>
    <?php endif; ?>
    <button type="submit" class="btn btn-danger" onclick="return confirm('This is permanent. Delete this account?');">Delete account</button>
  </form>
</div>

<?php else: ?>
<div class="card"><p class="text-faint">This account has been deleted. Its identity is scrubbed; transaction history below is preserved for audit purposes only.</p></div>
<?php endif; ?>

<div class="card">
  <h2>Referred by this user (<?= count($referrals) ?>)</h2>
  <?php if (empty($referrals)): ?>
    <p class="text-faint">No referrals yet.</p>
  <?php else: ?>
    <table class="data">
      <thead><tr><th>Username</th><th>Status</th><th>Joined</th></tr></thead>
      <tbody>
        <?php foreach ($referrals as $r): ?>
        <tr>
          <td><a href="/admin/user_detail.php?id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'deleted' ? '<span class="text-faint">Deleted account</span>' : admin_e($r['username']) ?></a></td>
          <td><span class="badge <?= admin_e($r['status']) ?>"><?= admin_e($r['status']) ?></span></td>
          <td class="text-faint"><?= admin_e(substr($r['created_at'], 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Transaction history</h2>
  <table class="data">
    <thead><tr><th>Type</th><th>Amount</th><th>Status</th><th>Description</th><th>Date</th></tr></thead>
    <tbody>
      <?php foreach ($transactions as $tx): ?>
      <tr>
        <td><?= admin_e(str_replace('_', ' ', $tx['type'])) ?></td>
        <td class="mono"><?= (float)$tx['amount'] >= 0 ? '+' : '' ?>KSh <?= number_format((float)$tx['amount'], 2) ?></td>
        <td><span class="badge <?= admin_e($tx['status']) ?>"><?= admin_e($tx['status']) ?></span></td>
        <td class="text-faint"><?= admin_e($tx['description'] ?? '—') ?></td>
        <td class="text-faint"><?= admin_e($tx['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($transactions)): ?>
        <tr><td colspan="5" class="text-faint">No transactions yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
