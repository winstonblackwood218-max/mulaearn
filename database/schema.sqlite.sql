-- SQLite mirror of database/schema.sql, used only by the local dev
-- server (php artisan-style migrations aren't in scope for this
-- slice). Column names, types, and constraints match the MySQL
-- version as closely as SQLite allows. Do not use this file in
-- production — schema.sql is the source of truth for MySQL/MariaDB.

PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    username            VARCHAR(30)  NOT NULL UNIQUE,
    email               VARCHAR(190) NOT NULL UNIQUE,
    phone               VARCHAR(20),
    country_code        CHAR(2)      NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    referred_by_user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    status              VARCHAR(10) NOT NULL DEFAULT 'active', -- active | suspended | deleted
    withdrawal_suspended INTEGER NOT NULL DEFAULT 0,
    email_verified_at   DATETIME NULL,
    phone_verified_at   DATETIME NULL,
    failed_login_count  INTEGER NOT NULL DEFAULT 0,
    locked_until        DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_users_referred_by ON users(referred_by_user_id);

CREATE TABLE wallets (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    available_balance  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    pending_balance    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    lifetime_earnings  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type          VARCHAR(30) NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    status        VARCHAR(10) NOT NULL DEFAULT 'pending',
    reference     VARCHAR(64) NULL,
    description   VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_tx_user ON transactions(user_id, created_at);

CREATE TABLE settings (
    `key`        VARCHAR(64) PRIMARY KEY,
    `value`      VARCHAR(255) NOT NULL,
    description  VARCHAR(255) NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE login_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    email_tried VARCHAR(190) NULL,
    ip_address  VARCHAR(45) NOT NULL,
    success     INTEGER NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------
-- Admin panel (slice 2)
-- ---------------------------------------------------------------

CREATE TABLE admin_users (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    username            VARCHAR(30)  NOT NULL UNIQUE,
    email               VARCHAR(190) NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,
    role                VARCHAR(12) NOT NULL DEFAULT 'support',
    status              VARCHAR(10) NOT NULL DEFAULT 'active',
    failed_login_count  INTEGER NOT NULL DEFAULT 0,
    locked_until        DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE admin_logs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id      INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    action        VARCHAR(64) NOT NULL,
    target_type   VARCHAR(32) NULL,
    target_id     INTEGER NULL,
    details       VARCHAR(500) NULL,
    ip_address    VARCHAR(45) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_admin_logs_admin ON admin_logs(admin_id, created_at);

INSERT INTO settings (`key`, `value`, description) VALUES
  ('referral_bonus_amount', '50.00', 'One-time bonus paid to a referrer when their referral completes a qualifying action (not on signup/payment).'),
  ('min_withdrawal_amount', '200.00', 'Minimum balance a user can request as a withdrawal, in the local currency.'),
  ('points_per_currency_unit', '10', 'How many Mula Points equal 1 unit of local currency, e.g. 10 points = KSh 1.');

-- ===================== Affiliate system (slice 2) =====================

CREATE TABLE affiliate_campaigns (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    brand_name         VARCHAR(100) NOT NULL,
    title              VARCHAR(150) NOT NULL,
    category           VARCHAR(50)  NOT NULL,
    description        VARCHAR(500) NULL,
    target_url         VARCHAR(500) NOT NULL,
    commission_amount  DECIMAL(12,2) NOT NULL,
    status             VARCHAR(10) NOT NULL DEFAULT 'active', -- active | paused | ended
    requirements       VARCHAR(255) NULL,
    starts_at          DATETIME NULL,
    ends_at            DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One link per (user, campaign) pair, generated on first "Promote" and reused after.
CREATE TABLE affiliate_links (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    campaign_id    INTEGER NOT NULL REFERENCES affiliate_campaigns(id) ON DELETE CASCADE,
    tracking_code  VARCHAR(20) NOT NULL UNIQUE,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, campaign_id)
);
CREATE INDEX idx_links_user ON affiliate_links(user_id);

-- ip_hash/user_agent_hash, never raw IP/UA — enough for fraud pattern
-- detection (many clicks, one hash) without storing identifying data.
CREATE TABLE affiliate_clicks (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id          INTEGER NOT NULL REFERENCES affiliate_links(id) ON DELETE CASCADE,
    ip_hash          VARCHAR(64) NOT NULL,
    user_agent_hash  VARCHAR(64) NULL,
    clicked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_clicks_link ON affiliate_clicks(link_id, clicked_at);

-- Audit record of "an admin recorded a conversion for this link" —
-- the actual money is a transactions row (transaction_id), whose
-- pending/completed/failed status is the source of truth. This table
-- never itself represents a balance change.
CREATE TABLE affiliate_conversions (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id               INTEGER NOT NULL REFERENCES affiliate_links(id) ON DELETE CASCADE,
    transaction_id        INTEGER NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    commission_amount     DECIMAL(12,2) NOT NULL,
    recorded_by_admin_id  INTEGER NOT NULL REFERENCES admin_users(id),
    note                  VARCHAR(255) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_conversions_link ON affiliate_conversions(link_id);

INSERT INTO affiliate_campaigns (brand_name, title, category, description, target_url, commission_amount, status, requirements) VALUES
  ('Nova Devices', 'Smartphone Launch Bundle', 'Technology', 'Promote the new Nova mid-range smartphone bundle to your audience.', 'https://example.com/campaigns/nova-smartphone', 300.00, 'active', 'Any active social or blog presence'),
  ('Kesho Finance', 'Savings App Signup', 'Finance', 'Refer new users to the Kesho savings and budgeting app.', 'https://example.com/campaigns/kesho-savings', 450.00, 'active', 'Finance-relevant audience preferred'),
  ('GreenLeaf Beauty', 'Skincare Starter Kit', 'Beauty', 'Share GreenLeaf''s new skincare starter kit with your followers.', 'https://example.com/campaigns/greenleaf-skincare', 180.00, 'active', 'Instagram or TikTok account');
