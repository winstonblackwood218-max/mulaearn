<?php
// src/WalletService.php — the ONLY code path allowed to change a
// wallet balance. No route, no form field, no API input ever sets
// available_balance/pending_balance directly — everything goes
// through credit()/debit(), which write a transactions row and
// update the wallet cache inside the same DB transaction.

declare(strict_types=1);

final class WalletService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    private const VALID_TYPES = [
        'affiliate_commission', 'referral_bonus', 'activity_reward',
        'competition_reward', 'bonus', 'withdrawal', 'admin_adjustment',
    ];

    /**
     * Credit a user's PENDING balance (e.g. a just-approved affiliate
     * conversion). Pending, not available — see releasePending().
     */
    public function credit(int $userId, string $type, int|float|string $amount, ?string $reference = null, ?string $description = null): int
    {
        $this->assertValidType($type);
        $this->assertPositiveAmount($amount);
        $amount = number_format((float)$amount, 2, '.', '');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO transactions (user_id, type, amount, status, reference, description)
                 VALUES (:uid, :type, :amount, :status, :ref, :desc)'
            );
            $stmt->execute([
                ':uid' => $userId, ':type' => $type, ':amount' => $amount,
                ':status' => 'pending', ':ref' => $reference, ':desc' => $description,
            ]);
            $txId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare(
                'UPDATE wallets SET pending_balance = pending_balance + :amount WHERE user_id = :uid'
            );
            $stmt->execute([':amount' => $amount, ':uid' => $userId]);

            $this->db->commit();
            return $txId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Move a specific pending transaction to completed/available —
     * the "admin approval" step. Never called from a user-facing route.
     */
    public function releasePending(int $transactionId): void
    {
        $this->db->beginTransaction();
        try {
            if ($this->isSqlite()) {
                // SQLite has no row-level locking; the transaction itself
                // is enough isolation for this project's concurrency needs.
                $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id = :id AND status = \'pending\'');
            } else {
                $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id = :id AND status = \'pending\' FOR UPDATE');
            }
            $stmt->execute([':id' => $transactionId]);
            $tx = $stmt->fetch();
            if (!$tx) {
                throw new RuntimeException('Transaction not found or already processed.');
            }

            $stmt = $this->db->prepare('UPDATE transactions SET status = \'completed\' WHERE id = :id');
            $stmt->execute([':id' => $transactionId]);

            $stmt = $this->db->prepare(
                'UPDATE wallets SET
                    pending_balance = pending_balance - :amount,
                    available_balance = available_balance + :amount,
                    lifetime_earnings = lifetime_earnings + :amount
                 WHERE user_id = :uid'
            );
            $stmt->execute([':amount' => $tx['amount'], ':uid' => $tx['user_id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Reserve funds for a withdrawal request — moves available -> held via a negative pending tx. */
    public function requestWithdrawal(int $userId, int|float|string $amount): int
    {
        $this->assertPositiveAmount($amount);
        $amount = number_format((float)$amount, 2, '.', '');

        $this->db->beginTransaction();
        try {
            // Checked here too, not just in public/api/withdraw.php — this
            // method is the actual money-moving boundary, so it can't rely
            // on every future caller remembering to check first.
            $stmt = $this->db->prepare('SELECT withdrawal_suspended FROM users WHERE id = :uid');
            $stmt->execute([':uid' => $userId]);
            $suspended = $stmt->fetchColumn();
            if ($suspended === false) {
                throw new RuntimeException('User not found.');
            }
            if ((int)$suspended === 1) {
                throw new RuntimeException('Withdrawals are suspended for this account.');
            }

            $stmt = $this->db->prepare('SELECT available_balance FROM wallets WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            $available = (float)$stmt->fetchColumn();

            if ($available < (float)$amount) {
                throw new RuntimeException('Insufficient available balance.');
            }

            $stmt = $this->db->prepare(
                'INSERT INTO transactions (user_id, type, amount, status, description)
                 VALUES (:uid, \'withdrawal\', :amount, \'pending\', \'Withdrawal request\')'
            );
            $stmt->execute([':uid' => $userId, ':amount' => '-' . $amount]);
            $txId = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare(
                'UPDATE wallets SET available_balance = available_balance - :amount WHERE user_id = :uid'
            );
            $stmt->execute([':amount' => $amount, ':uid' => $userId]);

            $this->db->commit();
            return $txId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Admin-only, direct correction to a user's AVAILABLE balance —
     * fixing an error, writing off a dispute, an off-platform goodwill
     * credit, etc. Deliberately separate from credit()/withdrawal():
     * this is the one path that changes available_balance immediately
     * with no pending step, precisely because it's meant for rare,
     * manually-reviewed, individually-justified corrections rather
     * than routine earning flows.
     *
     * $delta may be positive (credit) or negative (debit); a negative
     * delta that would take available_balance below zero is rejected
     * rather than allowed to go negative. A reason is mandatory and is
     * stored on the transaction row for the audit trail. Positive
     * adjustments also increase lifetime_earnings (real money entered
     * the system); negative adjustments do not decrease it — money
     * once earned stays "earned" in that ledger even if later clawed
     * back from the spendable balance.
     */
    public function adminAdjustBalance(int $userId, int|float|string $delta, string $reason): int
    {
        if (!is_numeric($delta) || (float)$delta === 0.0) {
            throw new InvalidArgumentException('Adjustment amount must be a nonzero number.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for a manual balance adjustment.');
        }

        $delta = (float)$delta;
        $amountStr = number_format($delta, 2, '.', ''); // keeps sign

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT available_balance FROM wallets WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Wallet not found for this user.');
            }

            if ($delta < 0 && (float)$current + $delta < 0) {
                throw new RuntimeException('This adjustment would take the available balance below zero.');
            }

            $stmt = $this->db->prepare(
                "INSERT INTO transactions (user_id, type, amount, status, description)
                 VALUES (:uid, 'admin_adjustment', :amount, 'completed', :desc)"
            );
            $stmt->execute([':uid' => $userId, ':amount' => $amountStr, ':desc' => $reason]);
            $txId = (int)$this->db->lastInsertId();

            if ($delta > 0) {
                $stmt = $this->db->prepare(
                    'UPDATE wallets SET available_balance = available_balance + :amount, lifetime_earnings = lifetime_earnings + :amount WHERE user_id = :uid'
                );
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE wallets SET available_balance = available_balance + :amount WHERE user_id = :uid'
                );
            }
            $stmt->execute([':amount' => $amountStr, ':uid' => $userId]);

            $this->db->commit();
            return $txId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function balanceFor(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT available_balance, pending_balance, lifetime_earnings FROM wallets WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Everything the dashboard needs in one call. Computed with direct
     * SQL aggregates over the FULL transaction history — not derived
     * from recentTransactions()'s limited page, which would silently
     * undercount once a user has more than a handful of transactions.
     */
    public function summaryFor(int $userId): array
    {
        $balance = $this->balanceFor($userId) ?? [
            'available_balance' => 0, 'pending_balance' => 0, 'lifetime_earnings' => 0,
        ];

        $todayCondition = $this->isSqlite()
            ? "date(created_at) = date('now')"
            : 'DATE(created_at) = CURDATE()';

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE user_id = :uid AND status = 'completed' AND amount > 0 AND {$todayCondition}"
        );
        $stmt->execute([':uid' => $userId]);
        $today = (float)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE user_id = :uid AND status = 'completed' AND type = 'affiliate_commission'"
        );
        $stmt->execute([':uid' => $userId]);
        $affiliate = (float)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE user_id = :uid AND status = 'completed' AND type = 'referral_bonus'"
        );
        $stmt->execute([':uid' => $userId]);
        $referral = (float)$stmt->fetchColumn();

        return [
            'available_balance'  => (float)$balance['available_balance'],
            'pending_balance'    => (float)$balance['pending_balance'],
            'lifetime_earnings'  => (float)$balance['lifetime_earnings'],
            'today_earnings'     => $today,
            'affiliate_earnings' => $affiliate,
            'referral_earnings'  => $referral,
        ];
    }

    public function recentTransactions(int $userId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM transactions WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Admin action: reject a pending credit (affiliate commission,
     * referral bonus, etc.) instead of approving it — e.g. the
     * advertiser disputed the conversion. Reverses the pending_balance
     * increase that credit() made; never touches available_balance,
     * since the funds never reached it.
     */
    public function rejectPending(int $transactionId, string $reason): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM transactions WHERE id = :id AND status = 'pending' AND type != 'withdrawal'"
            );
            $stmt->execute([':id' => $transactionId]);
            $tx = $stmt->fetch();
            if (!$tx) {
                throw new RuntimeException('Transaction not found or already processed.');
            }

            $stmt = $this->db->prepare("UPDATE transactions SET status = 'failed', description = :desc WHERE id = :id");
            $stmt->execute([':id' => $transactionId, ':desc' => 'Rejected: ' . $reason]);

            $stmt = $this->db->prepare('UPDATE wallets SET pending_balance = pending_balance - :amount WHERE user_id = :uid');
            $stmt->execute([':amount' => $tx['amount'], ':uid' => $tx['user_id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Admin action: mark a withdrawal as completed AFTER verified
     * payment confirmation from the payment provider. This method
     * itself does not talk to any payment API — it's the recording
     * step, called once the admin has that confirmation in hand
     * (manually today; automatically once a payment webhook exists).
     */
    public function completeWithdrawal(int $transactionId, string $reference): void
    {
        $stmt = $this->db->prepare(
            "UPDATE transactions SET status = 'completed', reference = :ref WHERE id = :id AND type = 'withdrawal' AND status = 'pending'"
        );
        $stmt->execute([':id' => $transactionId, ':ref' => $reference]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Withdrawal not found or already processed.');
        }
    }

    /**
     * Admin action: a withdrawal failed at the payment provider —
     * refund the reserved amount back to available_balance.
     */
    public function failWithdrawal(int $transactionId, string $reason): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM transactions WHERE id = :id AND type = 'withdrawal' AND status = 'pending'"
            );
            $stmt->execute([':id' => $transactionId]);
            $tx = $stmt->fetch();
            if (!$tx) {
                throw new RuntimeException('Withdrawal not found or already processed.');
            }

            $stmt = $this->db->prepare(
                "UPDATE transactions SET status = 'failed', description = :desc WHERE id = :id"
            );
            $stmt->execute([':id' => $transactionId, ':desc' => 'Failed: ' . $reason]);

            // tx.amount is negative for withdrawals; subtracting a negative refunds it.
            $refund = ltrim((string)$tx['amount'], '-');
            $stmt = $this->db->prepare(
                'UPDATE wallets SET available_balance = available_balance + :amount WHERE user_id = :uid'
            );
            $stmt->execute([':amount' => $refund, ':uid' => $tx['user_id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Admin view: all pending, non-withdrawal transactions awaiting approval. */
    public function pendingApprovals(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, u.username FROM transactions t
             JOIN users u ON u.id = t.user_id
             WHERE t.status = 'pending' AND t.type != 'withdrawal'
             ORDER BY t.created_at ASC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Admin view: all pending withdrawal requests. */
    public function pendingWithdrawals(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, u.username FROM transactions t
             JOIN users u ON u.id = t.user_id
             WHERE t.status = 'pending' AND t.type = 'withdrawal'
             ORDER BY t.created_at ASC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function transactionById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private function assertValidType(string $type): void
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Invalid transaction type: {$type}");
        }
    }

    private function assertPositiveAmount(int|float|string $amount): void
    {
        if (!is_numeric($amount) || (float)$amount <= 0) {
            throw new InvalidArgumentException('Amount must be a positive number.');
        }
    }

    private function isSqlite(): bool
    {
        $config = require __DIR__ . '/../config/config.php';
        return $config['db']['driver'] === 'sqlite';
    }
}
