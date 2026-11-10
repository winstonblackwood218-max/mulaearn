<?php
// src/AdminAuth.php — completely separate from src/Auth.php.
//
// Deliberate isolation from the regular user auth system:
//   * own table (admin_users), own session keys, own lockout counter
//   * shorter session lifetime, stricter password minimum
//   * every successful admin login and every admin action is logged
// A bug or compromise in one system should never grant access to
// the other.

declare(strict_types=1);

final class AdminAuth
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public function login(string $identifier, string $password, string $ip): array
    {
        $identifier = trim(mb_strtolower($identifier));
        $admin = $this->findByEmail($identifier) ?? $this->findByUsername($identifier);

        if (!$admin) {
            return ['success' => false, 'error' => 'Incorrect email/username or password.'];
        }

        if (!empty($admin['locked_until']) && strtotime($admin['locked_until']) > time()) {
            return ['success' => false, 'error' => 'Too many failed attempts. Try again later.'];
        }

        if (!password_verify($password, $admin['password_hash'])) {
            $this->registerFailedAttempt((int)$admin['id']);
            return ['success' => false, 'error' => 'Incorrect email/username or password.'];
        }

        if ($admin['status'] !== 'active') {
            return ['success' => false, 'error' => 'This admin account is suspended.'];
        }

        $stmt = $this->db->prepare('UPDATE admin_users SET failed_login_count = 0, locked_until = NULL WHERE id = :id');
        $stmt->execute([':id' => $admin['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['admin_logged_in_at'] = time();

        (new AdminLogger())->log((int)$admin['id'], 'login', null, null, null, $ip);

        return ['success' => true, 'error' => null];
    }

    public function logout(): void
    {
        $adminId = $_SESSION['admin_id'] ?? null;
        if ($adminId) {
            (new AdminLogger())->log((int)$adminId, 'logout', null, null, null, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        }
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role'], $_SESSION['admin_logged_in_at']);
        session_regenerate_id(true);
    }

    public function currentAdmin(): ?array
    {
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, username, email, role, status FROM admin_users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        return $admin ?: null;
    }

    private function registerFailedAttempt(int $adminId): void
    {
        $max = $this->config['security']['max_login_attempts'];
        $lockoutMinutes = $this->config['security']['lockout_minutes'];

        $stmt = $this->db->prepare('SELECT failed_login_count FROM admin_users WHERE id = :id');
        $stmt->execute([':id' => $adminId]);
        $count = (int)$stmt->fetchColumn() + 1;

        if ($count >= $max) {
            $lockUntil = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
            $stmt = $this->db->prepare('UPDATE admin_users SET failed_login_count = :c, locked_until = :until WHERE id = :id');
            $stmt->execute([':c' => $count, ':until' => $lockUntil, ':id' => $adminId]);
        } else {
            $stmt = $this->db->prepare('UPDATE admin_users SET failed_login_count = :c WHERE id = :id');
            $stmt->execute([':c' => $count, ':id' => $adminId]);
        }
    }

    private function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return $stmt->fetch() ?: null;
    }

    private function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE email = :e');
        $stmt->execute([':e' => $email]);
        return $stmt->fetch() ?: null;
    }
}
