<?php
// public/admin/includes/layout_top.php
// Expects $admin (from require_admin()) and $activeNav (string) to be set by the including page.
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? admin_e($pageTitle) . ' — ' : '' ?>MulaEarn Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <div class="brand">MulaEarn Admin</div>
    <nav>
      <a href="/admin/index.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="/admin/users.php" class="<?= $activeNav === 'users' ? 'active' : '' ?>">Users</a>
      <a href="/admin/campaigns.php" class="<?= $activeNav === 'campaigns' ? 'active' : '' ?>">Campaigns</a>
      <a href="/admin/conversions.php" class="<?= $activeNav === 'conversions' ? 'active' : '' ?>">Record Conversion</a>
      <a href="/admin/transactions.php" class="<?= $activeNav === 'transactions' ? 'active' : '' ?>">Approvals</a>
      <a href="/admin/withdrawals.php" class="<?= $activeNav === 'withdrawals' ? 'active' : '' ?>">Withdrawals</a>
      <a href="/admin/settings.php" class="<?= $activeNav === 'settings' ? 'active' : '' ?>">Settings</a>
      <a href="/admin/logs.php" class="<?= $activeNav === 'logs' ? 'active' : '' ?>">Audit Log</a>
    </nav>
    <div class="who">
      Logged in as
      <b><?= admin_e($admin['username']) ?></b>
      <?= admin_e($admin['role']) ?>
    </div>
    <form method="post" action="/admin/logout.php">
      <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
      <button type="submit" class="logout-btn">Log out</button>
    </form>
  </aside>
  <main class="admin-main">
