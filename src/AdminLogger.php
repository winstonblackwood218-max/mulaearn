<?php
// src/AdminLogger.php — write-only audit trail. Every admin action
// that touches a user, a transaction, or a setting goes through
// here. Rows are never updated or deleted by application code.

declare(strict_types=1);

final class AdminLogger
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function log(int $adminId, string $action, ?string $targetType, ?int $targetId, ?string $details, string $ip): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip_address)
             VALUES (:admin_id, :action, :target_type, :target_id, :details, :ip)'
        );
        $stmt->execute([
            ':admin_id'    => $adminId,
            ':action'      => $action,
            ':target_type' => $targetType,
            ':target_id'   => $targetId,
            ':details'     => $details,
            ':ip'          => $ip,
        ]);
    }

    public function recent(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT al.*, au.username AS admin_username
             FROM admin_logs al
             JOIN admin_users au ON au.id = al.admin_id
             ORDER BY al.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
