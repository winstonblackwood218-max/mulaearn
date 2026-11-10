<?php
// src/SettingsService.php — the ONLY place business values like the
// referral bonus, minimum withdrawal, or points conversion rate live.
// Nothing in this project hard-codes these in PHP; every read goes
// through here so a future page/API for a given value pulls the
// admin-edited number automatically.

declare(strict_types=1);

final class SettingsService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function all(): array
    {
        $stmt = $this->db->query('SELECT `key`, `value`, description, updated_at FROM settings ORDER BY `key`');
        return $stmt->fetchAll();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT `value` FROM settings WHERE `key` = :key');
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare('UPDATE settings SET `value` = :value WHERE `key` = :key');
        $stmt->execute([':value' => $value, ':key' => $key]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException("Unknown setting: {$key}");
        }
    }
}
