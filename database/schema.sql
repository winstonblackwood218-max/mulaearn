-- MulaEarn — core schema (slice 1: users, auth, wallets, settings)
--
-- Written to run on MySQL/MariaDB in production. This project runs it
-- through SQLite locally for zero-dependency testing — see
-- database/schema.sqlite.sql for the SQLite-compatible mirror used by
-- the dev server, and src/Database.php for how the driver is chosen.
--
-- Conventions:
--   * All money columns are DECIMAL(12,2) — never FLOAT.
--   * Every table has created_at; mutable tables also have updated_at.
--   * Nothing here lets a client set a balance directly — wallets are
--     only ever changed by writing a transactions row inside a DB
--     transaction (see src/WalletService.php).

CREATE TABLE users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username            VARCHAR(30)  NOT NULL UNIQUE,
    email               VARCHAR(190) NOT NULL UNIQUE,
    phone               VARCHAR(20)  NULL,
    country_code        CHAR(2)      NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    referred_by_user_id INT UNSIGNED NULL,
    status              ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
    withdrawal_suspended TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at   DATETIME NULL,
    phone_verified_at   DATETIME NULL,
    failed_login_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (referred_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_users_referred_by (referred_by_user_id)
) ENGINE=InnoDB;

CREATE TABLE wallets (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL UNIQUE,
    available_balance  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pending_balance    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lifetime_earnings  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Every balance-affecting event, ever. This is the source of truth;
-- wallets.* columns are a maintained cache of sums over this table.
CREATE TABLE transactions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    type          ENUM(
                    'affiliate_commission','referral_bonus','activity_reward',
                    'competition_reward','bonus','withdrawal','admin_adjustment'
                  ) NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,          -- positive = credit, negative = debit
    status        ENUM('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    reference     VARCHAR(64) NULL,                -- e.g. affiliate_conversions.id, external payout id
    description   VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_tx_user (user_id, created_at)
) ENGINE=InnoDB;

-- Admin-configurable values — nothing financial is hard-coded in PHP.
-- Seeded with the Kenya defaults from the spec, but every value here
-- is editable without a code deploy.
CREATE TABLE settings (
    `key`        VARCHAR(64) PRIMARY KEY,
    `value`      VARCHAR(255) NOT NULL,
    description  VARCHAR(255) NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE login_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    email_tried VARCHAR(190) NULL,
    ip_address  VARCHAR(45) NOT NULL,
    success     TINYINT(1) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Admin panel (slice 2)
-- ---------------------------------------------------------------

-- Deliberately a separate table from `users`, not a role flag on it.
-- Admin credentials never share a table, a session cookie, or a
-- lockout counter with regular user accounts.
CREATE TABLE admin_users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username            VARCHAR(30)  NOT NULL UNIQUE,
    email               VARCHAR(190) NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,
    role                ENUM('superadmin','support') NOT NULL DEFAULT 'support',
    status              ENUM('active','suspended') NOT NULL DEFAULT 'active',
    failed_login_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Immutable audit trail. Rows are inserted only, never updated or
-- deleted by application code — this is what "who approved this
-- withdrawal" gets answered from during a dispute.
CREATE TABLE admin_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id      INT UNSIGNED NOT NULL,
    action        VARCHAR(64) NOT NULL,
    target_type   VARCHAR(32) NULL,
    target_id     INT UNSIGNED NULL,
    details       VARCHAR(500) NULL,
    ip_address    VARCHAR(45) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_admin_logs_admin (admin_id, created_at)
) ENGINE=InnoDB;

INSERT INTO settings (`key`, `value`, description) VALUES
  ('referral_bonus_amount', '50.00', 'One-time bonus paid to a referrer when their referral completes a qualifying action (not on signup/payment).'),
  ('min_withdrawal_amount', '200.00', 'Minimum balance a user can request as a withdrawal, in the local currency.'),
  ('points_per_currency_unit', '10', 'How many Mula Points equal 1 unit of local currency, e.g. 10 points = KSh 1.');

-- ===================== Affiliate system (slice 2) =====================

CREATE TABLE affiliate_campaigns (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_name         VARCHAR(100) NOT NULL,
    title              VARCHAR(150) NOT NULL,
    category           VARCHAR(50)  NOT NULL,
    description        VARCHAR(500) NULL,
    target_url         VARCHAR(500) NOT NULL,
    commission_amount  DECIMAL(12,2) NOT NULL,
    status             ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    requirements       VARCHAR(255) NULL,
    starts_at          DATETIME NULL,
    ends_at            DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One link per (user, campaign) pair, generated on first "Promote" and reused after.
CREATE TABLE affiliate_links (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    campaign_id    INT UNSIGNED NOT NULL,
    tracking_code  VARCHAR(20) NOT NULL UNIQUE,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES affiliate_campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_campaign (user_id, campaign_id),
    INDEX idx_links_user (user_id)
) ENGINE=InnoDB;

-- ip_hash/user_agent_hash, never raw IP/UA — enough for fraud pattern
-- detection (many clicks, one hash) without storing identifying data.
CREATE TABLE affiliate_clicks (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id          INT UNSIGNED NOT NULL,
    ip_hash          VARCHAR(64) NOT NULL,
    user_agent_hash  VARCHAR(64) NULL,
    clicked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (link_id) REFERENCES affiliate_links(id) ON DELETE CASCADE,
    INDEX idx_clicks_link (link_id, clicked_at)
) ENGINE=InnoDB;

-- Audit record of "an admin recorded a conversion for this link" —
-- the actual money is a transactions row (transaction_id), whose
-- pending/completed/failed status is the source of truth. This table
-- never itself represents a balance change.
CREATE TABLE affiliate_conversions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id               INT UNSIGNED NOT NULL,
    transaction_id        INT UNSIGNED NOT NULL,
    commission_amount     DECIMAL(12,2) NOT NULL,
    recorded_by_admin_id  INT UNSIGNED NOT NULL,
    note                  VARCHAR(255) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (link_id) REFERENCES affiliate_links(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by_admin_id) REFERENCES admin_users(id),
    INDEX idx_conversions_link (link_id)
) ENGINE=InnoDB;

INSERT INTO affiliate_campaigns (brand_name, title, category, description, target_url, commission_amount, status, requirements) VALUES
  ('Nova Devices', 'Smartphone Launch Bundle', 'Technology', 'Promote the new Nova mid-range smartphone bundle to your audience.', 'https://example.com/campaigns/nova-smartphone', 300.00, 'active', 'Any active social or blog presence'),
  ('Kesho Finance', 'Savings App Signup', 'Finance', 'Refer new users to the Kesho savings and budgeting app.', 'https://example.com/campaigns/kesho-savings', 450.00, 'active', 'Finance-relevant audience preferred'),
  ('GreenLeaf Beauty', 'Skincare Starter Kit', 'Beauty', 'Share GreenLeaf''s new skincare starter kit with your followers.', 'https://example.com/campaigns/greenleaf-skincare', 180.00, 'active', 'Instagram or TikTok account');
