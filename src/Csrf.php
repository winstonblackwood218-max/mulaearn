<?php
// src/Csrf.php — one CSRF token per session, checked on every
// state-changing request (register, login, logout, and anything
// added later that writes data).

declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $submitted): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($submitted)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submitted);
    }
}
