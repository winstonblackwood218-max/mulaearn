#!/usr/bin/env php
<?php
// bin/create_admin.php — run once (and again for each new admin).
// Deliberately interactive/CLI-only: there is no seeded default admin
// account anywhere in this project, because a shipped default
// username/password is one of the most common ways admin panels get
// broken into.
//
// Usage:
//   php bin/create_admin.php
//   DB_DRIVER=sqlite DB_DATABASE=./storage/mulaearn.sqlite php bin/create_admin.php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';

function prompt(string $label, bool $hidden = false): string
{
    echo $label;
    if ($hidden && stripos(PHP_OS, 'WIN') !== 0) {
        system('stty -echo');
        $value = trim((string)fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $value = trim((string)fgets(STDIN));
    }
    return $value;
}

echo "=== MulaEarn — create admin account ===\n";

$username = prompt('Username: ');
$email    = prompt('Email: ');
$password = prompt('Password (min 12 chars): ', true);
$confirm  = prompt('Confirm password: ', true);
$roleIn   = prompt('Role [support/superadmin] (default: support): ');
$role     = in_array($roleIn, ['support', 'superadmin'], true) ? $roleIn : 'support';

if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    fwrite(STDERR, "Invalid username. Letters, numbers, underscores, 3-30 chars.\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters for admin accounts.\n");
    exit(1);
}
if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$db = Database::connection();

$stmt = $db->prepare('SELECT id FROM admin_users WHERE username = :u OR email = :e');
$stmt->execute([':u' => $username, ':e' => $email]);
if ($stmt->fetch()) {
    fwrite(STDERR, "An admin with that username or email already exists.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = $db->prepare(
    'INSERT INTO admin_users (username, email, password_hash, role, status) VALUES (:u, :e, :h, :r, \'active\')'
);
$stmt->execute([':u' => $username, ':e' => $email, ':h' => $hash, ':r' => $role]);

echo "Admin '{$username}' created with role '{$role}'.\n";
