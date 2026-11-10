<?php
// src/Auth.php — registration, login, logout, session handling.
//
// Design choices worth calling out:
//   * Passwords hashed with password_hash() (bcrypt), never anything
//     reversible, never plain text.
//   * session_regenerate_id() on every privilege change (login,
//     logout) to prevent session fixation.
//   * Login attempts are rate-limited per account (not just per IP —
//     an attacker can rotate IPs, an account is a fixed target).
//   * Referral capture is READ-ONLY from the user's perspective: the
//     sponsor is resolved server-side from the ?ref= value at
//     registration time and then never editable again. There is no
//     multi-level commission logic here — see AGREED_SCOPE.md.

declare(strict_types=1);

final class Auth
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    /**
     * @return array{success:bool, errors:array<string>, user_id:?int}
     */
    public function register(array $input): array
    {
        $errors = [];

        $username = trim((string)($input['username'] ?? ''));
        $email    = trim(mb_strtolower((string)($input['email'] ?? '')));
        $phone    = trim((string)($input['phone'] ?? ''));
        $country  = strtoupper(trim((string)($input['country_code'] ?? '')));
        $password = (string)($input['password'] ?? '');
        $confirm  = (string)($input['confirm_password'] ?? '');
        $refCode  = trim((string)($input['ref'] ?? ''));

        if ($err = Validator::username($username)) $errors[] = $err;
        if ($err = Validator::email($email)) $errors[] = $err;
        if ($err = Validator::countryCode($country)) $errors[] = $err;
        if ($err = Validator::password($password, $this->config['security']['password_min_length'])) $errors[] = $err;
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        $referrerId = null;
        if ($refCode !== '') {
            if ($err = Validator::referralCode($refCode)) {
                $errors[] = $err;
            } else {
                $referrer = $this->findByUsername($refCode);
                // Deliberately not an error if the ref code doesn't exist —
                // we just proceed without a sponsor, per "handle the error
                // professionally" rather than blocking registration.
                $referrerId = $referrer['id'] ?? null;
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors, 'user_id' => null];
        }

        if ($this->findByUsername($username)) {
            return ['success' => false, 'errors' => ['That username is already taken.'], 'user_id' => null];
        }
        if ($this->findByEmail($email)) {
            return ['success' => false, 'errors' => ['An account with that email already exists.'], 'user_id' => null];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, phone, country_code, password_hash, referred_by_user_id, status)
                 VALUES (:username, :email, :phone, :country, :hash, :referrer, :status)'
            );
            $stmt->execute([
                ':username' => $username,
                ':email'    => $email,
                ':phone'    => $phone !== '' ? $phone : null,
                ':country'  => $country,
                ':hash'     => $hash,
                ':referrer' => $referrerId,
                ':status'   => 'active',
            ]);
            $userId = (int)$this->db->lastInsertId();

            // Every user gets exactly one wallet, created empty. Nothing
            // ever writes to wallets.* directly except WalletService,
            // and only in response to a transactions row.
            $stmt = $this->db->prepare(
                'INSERT INTO wallets (user_id, available_balance, pending_balance, lifetime_earnings)
                 VALUES (:user_id, 0, 0, 0)'
            );
            $stmt->execute([':user_id' => $userId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'errors' => ['Registration failed. Please try again.'], 'user_id' => null];
        }

        return ['success' => true, 'errors' => [], 'user_id' => $userId];
    }

    /**
     * @return array{success:bool, error:?string}
     */
    public function login(string $identifier, string $password, string $ip): array
    {
        return $this->attemptLogin($identifier, $password, $ip);
    }

    private function attemptLogin(string $identifier, string $password, string $ip): array
    {
        $identifier = trim(mb_strtolower($identifier));
        $user = $this->findByEmail($identifier) ?? $this->findByUsername($identifier);

        if (!$user) {
            $this->logAttempt(null, $identifier, $ip, false);
            return ['success' => false, 'error' => 'Incorrect email/username or password.'];
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $this->logAttempt((int)$user['id'], $identifier, $ip, false);
            return ['success' => false, 'error' => 'Too many failed attempts. Try again later.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->registerFailedAttempt((int)$user['id']);
            $this->logAttempt((int)$user['id'], $identifier, $ip, false);
            return ['success' => false, 'error' => 'Incorrect email/username or password.'];
        }

        if ($user['status'] !== 'active') {
            $this->logAttempt((int)$user['id'], $identifier, $ip, false);
            return ['success' => false, 'error' => 'This account is not active. Contact support.'];
        }

        // Success: clear failed attempts, rotate session id, log in.
        $stmt = $this->db->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id');
        $stmt->execute([':id' => $user['id']]);

        $this->logAttempt((int)$user['id'], $identifier, $ip, true);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['logged_in_at'] = time();

        return ['success' => true, 'error' => null];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, username, email, country_code, status, created_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    private function registerFailedAttempt(int $userId): void
    {
        $max = $this->config['security']['max_login_attempts'];
        $lockoutMinutes = $this->config['security']['lockout_minutes'];

        $stmt = $this->db->prepare('SELECT failed_login_count FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $count = (int)$stmt->fetchColumn() + 1;

        if ($count >= $max) {
            $lockUntil = date('Y-m-d H:i:s', time() + $lockoutMinutes * 60);
            $stmt = $this->db->prepare('UPDATE users SET failed_login_count = :c, locked_until = :until WHERE id = :id');
            $stmt->execute([':c' => $count, ':until' => $lockUntil, ':id' => $userId]);
        } else {
            $stmt = $this->db->prepare('UPDATE users SET failed_login_count = :c WHERE id = :id');
            $stmt->execute([':c' => $count, ':id' => $userId]);
        }
    }

    private function logAttempt(?int $userId, string $emailTried, string $ip, bool $success): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_logs (user_id, email_tried, ip_address, success) VALUES (:uid, :email, :ip, :success)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':email'   => $emailTried,
            ':ip'      => $ip,
            ':success' => $success ? 1 : 0,
        ]);
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :e');
        $stmt->execute([':e' => $email]);
        return $stmt->fetch() ?: null;
    }
}
