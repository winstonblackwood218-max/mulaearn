<?php
// config/config.php — central config, no secrets hard-coded.
// Reads from environment variables so the same code deploys to any
// environment; falls back to local SQLite dev defaults if unset.

return [
    'app' => [
        'env'   => getenv('APP_ENV') ?: 'local',
        'debug' => getenv('APP_DEBUG') !== false ? filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN) : true,
        'url'   => getenv('APP_URL') ?: 'http://localhost:8080',
    ],

    'db' => [
        // driver: 'sqlite' for local dev/testing, 'mysql' for production.
        'driver'   => getenv('DB_DRIVER') ?: 'sqlite',
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: (__DIR__ . '/../storage/mulaearn.sqlite'),
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'            => 'mulaearn_session',
        'lifetime_seconds'=> 60 * 60 * 8, // 8 hours
    ],

    'security' => [
        'max_login_attempts'      => 5,
        'lockout_minutes'         => 15,
        'password_min_length'     => 8,
    ],

    'mail' => [
        // If smtp_host is unset, Mailer falls back to logging emails to
        // storage/mail.log instead of sending — see src/Mailer.php.
        // Never claim a send succeeded when it was actually just logged.
        'smtp_host'   => getenv('SMTP_HOST') ?: null,
        'smtp_port'   => (int)(getenv('SMTP_PORT') ?: 587),
        'encryption'  => getenv('SMTP_ENCRYPTION') ?: 'tls', // tls | ssl | none
        'smtp_user'   => getenv('SMTP_USER') ?: null,
        'smtp_pass'   => getenv('SMTP_PASS') ?: null,
        'from_email'  => getenv('MAIL_FROM') ?: 'no-reply@mulaearn.com',
        'from_name'   => getenv('MAIL_FROM_NAME') ?: 'MulaEarn',
        'log_path'    => getenv('MAIL_LOG_PATH') ?: (__DIR__ . '/../storage/mail.log'),
    ],
];
