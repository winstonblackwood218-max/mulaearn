<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$auth = new AdminAuth();
if ($auth->currentAdmin()) {
    header('Location: /admin/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = $auth->login((string)($_POST['identifier'] ?? ''), (string)($_POST['password'] ?? ''), admin_client_ip());
        if ($result['success']) {
            header('Location: /admin/index.php');
            exit;
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — MulaEarn</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body>
<div class="login-shell">
  <div class="login-card">
    <h1>MulaEarn Admin</h1>
    <p>Sign in with your admin credentials. This panel is separate from user accounts.</p>

    <?php if ($error): ?>
      <div class="flash error"><?= admin_e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/login.php">
      <input type="hidden" name="csrf_token" value="<?= admin_e(Csrf::token()) ?>">
      <div class="field">
        <label for="identifier">Username or email</label>
        <input type="text" id="identifier" name="identifier" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:11px;">Sign in</button>
    </form>
    <p class="text-faint" style="margin-top:16px;">No admin account yet? Run <code>php bin/create_admin.php</code> on the server.</p>
  </div>
</div>
</body>
</html>
