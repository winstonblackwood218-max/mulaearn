<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$logs = (new AdminLogger())->recent(100);

$db = Database::connection();
$stmt = $db->query(
    "SELECT * FROM login_logs ORDER BY created_at DESC LIMIT 30"
);
$loginLogs = $stmt->fetchAll();

$pageTitle = 'Audit Log';
$activeNav = 'logs';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div><h1>Audit log</h1><p>Every admin action is recorded here and is never edited or deleted by application code.</p></div>
</div>

<div class="card">
  <h2>Admin actions</h2>
  <table class="data">
    <thead><tr><th>Admin</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th><th>When</th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= admin_e($l['admin_username']) ?></td>
        <td><span class="badge pending"><?= admin_e($l['action']) ?></span></td>
        <td class="text-faint"><?= $l['target_type'] ? admin_e($l['target_type']) . ' #' . (int)$l['target_id'] : '—' ?></td>
        <td class="text-faint"><?= admin_e($l['details'] ?? '—') ?></td>
        <td class="mono text-faint"><?= admin_e($l['ip_address']) ?></td>
        <td class="text-faint"><?= admin_e($l['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
        <tr><td colspan="6" class="text-faint">No admin actions logged yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Recent login attempts (user-facing site)</h2>
  <table class="data">
    <thead><tr><th>Email/username tried</th><th>Result</th><th>IP</th><th>When</th></tr></thead>
    <tbody>
      <?php foreach ($loginLogs as $l): ?>
      <tr>
        <td><?= admin_e($l['email_tried'] ?? '—') ?></td>
        <td><span class="badge <?= $l['success'] ? 'completed' : 'failed' ?>"><?= $l['success'] ? 'Success' : 'Failed' ?></span></td>
        <td class="mono text-faint"><?= admin_e($l['ip_address']) ?></td>
        <td class="text-faint"><?= admin_e($l['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($loginLogs)): ?>
        <tr><td colspan="4" class="text-faint">No login attempts logged yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
