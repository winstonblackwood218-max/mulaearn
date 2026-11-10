<?php
// src/UserAdminService.php — admin-facing user management. Every
// mutation here is logged by the caller via AdminLogger (kept out of
// this class so it stays a plain data layer, easy to unit test).

declare(strict_types=1);

final class UserAdminService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function search(string $query = '', int $limit = 50): array
    {
        if ($query === '') {
            $stmt = $this->db->prepare(
                'SELECT u.*, w.available_balance, w.pending_balance FROM users u
                 LEFT JOIN wallets w ON w.user_id = u.id
                 ORDER BY u.created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare(
                'SELECT u.*, w.available_balance, w.pending_balance FROM users u
                 LEFT JOIN wallets w ON w.user_id = u.id
                 WHERE u.username LIKE :q OR u.email LIKE :q
                 ORDER BY u.created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':q', '%' . $query . '%');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findWithWallet(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.*, w.available_balance, w.pending_balance, w.lifetime_earnings,
                    sponsor.username AS sponsor_username
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             LEFT JOIN users sponsor ON sponsor.id = u.referred_by_user_id
             WHERE u.id = :id'
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public function referrals(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, username, created_at, status FROM users WHERE referred_by_user_id = :id ORDER BY created_at DESC');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetchAll();
    }

    public function setStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        $stmt = $this->db->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $userId]);
    }

    public function setWithdrawalSuspension(int $userId, bool $suspended): void
    {
        $stmt = $this->db->prepare('UPDATE users SET withdrawal_suspended = :v WHERE id = :id');
        $stmt->execute([':v' => $suspended ? 1 : 0, ':id' => $userId]);
    }

    /**
     * Admin correction of a user's username/email — e.g. fixing a typo
     * or removing an offensive username. Runs through the same
     * validators as self-registration, and still enforces uniqueness.
     */
    public function updateIdentity(int $userId, string $newUsername, string $newEmail): void
    {
        if ($err = Validator::username($newUsername)) {
            throw new InvalidArgumentException($err);
        }
        if ($err = Validator::email($newEmail)) {
            throw new InvalidArgumentException($err);
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE username = :u AND id != :id');
        $stmt->execute([':u' => $newUsername, ':id' => $userId]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('That username is already taken by another account.');
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = :e AND id != :id');
        $stmt->execute([':e' => mb_strtolower($newEmail), ':id' => $userId]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('That email is already used by another account.');
        }

        $stmt = $this->db->prepare('UPDATE users SET username = :u, email = :e WHERE id = :id');
        $stmt->execute([':u' => $newUsername, ':e' => mb_strtolower($newEmail), ':id' => $userId]);
    }

    /**
     * "Delete" an account WITHOUT a destructive SQL DELETE. A hard
     * delete would cascade away the user's transactions rows (see
     * schema FKs), destroying the financial audit trail for money
     * that may already have moved. Instead: block login, scrub PII,
     * and free the username/email for reuse. Transaction history and
     * wallet stay intact and attributable to this (now anonymized)
     * account id.
     *
     * Refuses to run if the user has a nonzero available balance
     * unless $force is true — deleting an account shouldn't silently
     * strand real money. Pass $force only after the balance has been
     * withdrawn or explicitly written off via adminAdjustBalance().
     */
    public function deleteAccount(int $userId, bool $force = false): void
    {
        $stmt = $this->db->prepare('SELECT u.status, w.available_balance FROM users u LEFT JOIN wallets w ON w.user_id = u.id WHERE u.id = :id');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('User not found.');
        }
        if ($row['status'] === 'deleted') {
            throw new RuntimeException('This account is already deleted.');
        }
        if (!$force && (float)($row['available_balance'] ?? 0) > 0) {
            throw new RuntimeException(
                'This account has a nonzero available balance. Resolve it (withdraw or adjust to zero) before deleting, or pass force.'
            );
        }

        $placeholder = 'deleted_user_' . $userId . '_' . bin2hex(random_bytes(4));
        $stmt = $this->db->prepare(
            "UPDATE users SET
                username = :username,
                email = :email,
                phone = NULL,
                password_hash = :hash,
                status = 'deleted'
             WHERE id = :id"
        );
        $stmt->execute([
            ':username' => $placeholder,
            ':email'    => $placeholder . '@deleted.mulaearn.invalid',
            ':hash'     => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), // unusable, unknown to anyone
            ':id'       => $userId,
        ]);
    }

    public function counts(): array
    {
        $total = (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $active = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
        $suspended = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();

        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE created_at >= :since');
        $stmt->execute([':since' => $since]);
        $newThisWeek = (int)$stmt->fetchColumn();

        return compact('total', 'active', 'suspended', 'newThisWeek');
    }
}
