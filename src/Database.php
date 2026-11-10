<?php
// src/Database.php — single PDO connection, prepared statements only.
// Never build SQL by concatenating user input, anywhere in this project.

declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = require __DIR__ . '/../config/config.php';
        $db = $config['db'];

        if ($db['driver'] === 'sqlite') {
            $dsn = 'sqlite:' . $db['database'];
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['database'],
                $db['charset']
            );
        }

        $pdo = new PDO($dsn, $db['username'] ?? null, $db['password'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($db['driver'] === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        self::$instance = $pdo;
        return $pdo;
    }
}
