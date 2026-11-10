<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$userSvc = new UserAdminService();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Form expired, please retry.'];
    } else {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newStatus = $_POST['action'] === 'suspend' ? 'suspended' : 'active';
        try {
            $userSvc->setStatus($targetId, $newStatus);
            (new AdminLogger())->log((int)$admin['id'], 'user.' . $_POST['action'], 'user', $targetId, null, admin_client_ip());
            $flash = ['type' => 'success', 'text' => "User #{$targetId} set to {$newStatus}."];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not update user status.'];
        }
    }
}

$query = trim((string)($_GET['q'] ?? ''));
$users = $userSvc->search($query);

$pageTitle = 'Users';
$activeNav = 'users';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div><h1>Users</h1><p><?= count($users) ?> shown<?= $query !== '' ? ' for "' . admin_e($query) . '"' : '' ?></p></div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<form method="get" class="search-row">
  <input type="text" name="q" value="<?= admin_e($query) ?>" placeholder="Search by username or email...">
  <button type="submit" class="btn">Search</button>
</form>

<div class="card">
  <table class="data">
    <thead><tr><th>User</th><th>Country</th><th>Balance</th><th>Sponsor</th><th>Status</th><th>Joined</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><a href="/admin/user_detail.php?id=<?= (int)$u['id'] ?>"><?= admin_e($u['username']) ?></a><br><span class="text-faint"><?= admin_e($u['email']) ?></span></td>
        <td><?= admin_e($u['country_code']) ?></td>
        <td class="mono">KSh <?= number_format((float)($u['available_balance'] ?? 0), 2) ?></td>
        <td class="text-faint">—</td>
        <td><span class="badge <?= admin_e($u['status']) ?>"><?= admin_e($u['status']) ?></span></td>
        <td class="text-faint"><?= admin_e(substr($u['created_at'], 0, 10)) ?></td>
        <td>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <?php if ($u['status'] === 'active'): ?>
              <button type="submit" name="action" value="suspend" class="btn btn-sm btn-danger" onclick="return confirm('Suspend this user?');">Suspend</button>
            <?php else: ?>
              <button type="submit" name="action" value="activate" class="btn btn-sm btn-primary">Reactivate</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
        <tr><td colspan="7" class="text-faint">No users found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
