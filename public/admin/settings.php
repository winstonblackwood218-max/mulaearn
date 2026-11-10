<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$admin = require_admin();

$settingsSvc = new SettingsService();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Form expired, please retry.'];
    } else {
        $key = (string)($_POST['key'] ?? '');
        $value = trim((string)($_POST['value'] ?? ''));
        try {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('Value must be numeric.');
            }
            $settingsSvc->set($key, $value);
            (new AdminLogger())->log((int)$admin['id'], 'setting.update', 'setting', null, "{$key} = {$value}", admin_client_ip());
            $flash = ['type' => 'success', 'text' => "Updated {$key}."];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'text' => 'Could not update: ' . $e->getMessage()];
        }
    }
}

$settings = $settingsSvc->all();

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="admin-topbar">
  <div><h1>Platform settings</h1><p>Nothing financial is hard-coded — every value here is read live by the application.</p></div>
</div>

<?php if ($flash): ?><div class="flash <?= $flash['type'] ?>"><?= admin_e($flash['text']) ?></div><?php endif; ?>

<div class="card">
  <table class="data">
    <thead><tr><th>Key</th><th>Description</th><th>Current value</th><th>Update</th><th>Last changed</th></tr></thead>
    <tbody>
      <?php foreach ($settings as $s): ?>
      <tr>
        <td class="mono"><?= admin_e($s['key']) ?></td>
        <td class="text-faint"><?= admin_e($s['description'] ?? '') ?></td>
        <td class="mono"><b><?= admin_e($s['value']) ?></b></td>
        <td>
          <form method="post" style="display:flex; gap:8px;">
            <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
            <input type="hidden" name="key" value="<?= admin_e($s['key']) ?>">
            <input type="text" name="value" value="<?= admin_e($s['value']) ?>" style="width:110px; padding:6px 8px; border:1px solid var(--line); border-radius:6px;">
            <button type="submit" class="btn btn-sm btn-primary">Save</button>
          </form>
        </td>
        <td class="text-faint"><?= admin_e($s['updated_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
