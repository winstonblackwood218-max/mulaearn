<?php
// src/Validator.php — small, dependency-free validation helpers.
// Every rule returns a plain-language error string, or null if the
// field passes. Keeping this framework-free is deliberate: it maps
// 1:1 onto whatever validation layer a future Laravel migration uses.

declare(strict_types=1);

final class Validator
{
    public static function username(string $value): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $value)) {
            return 'Username must be 3-30 characters: letters, numbers, and underscores only.';
        }
        return null;
    }

    public static function email(string $value): ?string
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }
        return null;
    }

    public static function password(string $value, int $minLength = 8): ?string
    {
        if (mb_strlen($value) < $minLength) {
            return "Password must be at least {$minLength} characters.";
        }
        if (!preg_match('/[A-Za-z]/', $value) || !preg_match('/[0-9]/', $value)) {
            return 'Password must include at least one letter and one number.';
        }
        return null;
    }

    public static function countryCode(string $value): ?string
    {
        if (!preg_match('/^[A-Z]{2}$/', $value)) {
            return 'Select a valid country.';
        }
        return null;
    }

    /** Referral usernames follow the same shape as normal usernames. */
    public static function referralCode(string $value): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_]{2,30}$/', $value)) {
            return 'Invalid referral code format.';
        }
        return null;
    }
}
