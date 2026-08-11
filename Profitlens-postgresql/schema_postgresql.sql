-- ══════════════════════════════════════════════════════════════════════
-- Profit Lens — PostgreSQL schema
-- Converted from the original MySQL/MariaDB dump (gdr__2_.sql)
--
-- Run this ONCE against your Render PostgreSQL database (or your local
-- Postgres) before deploying the app. In Render's dashboard: open your
-- Postgres instance → "Connect" → use the psql command shown, or paste
-- this whole file into a client like pgAdmin / DBeaver / TablePlus.
--
-- Example (from your own machine, with the psql client installed):
--   psql "<External Database URL from Render>" -f schema_postgresql.sql
-- ══════════════════════════════════════════════════════════════════════

BEGIN;

CREATE TABLE IF NOT EXISTS products (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    category    VARCHAR(100),
    price       DECIMAL(10,2) DEFAULT 0.00,
    cost        DECIMAL(10,2) DEFAULT 0.00,
    stock       INTEGER DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATE
);

CREATE TABLE IF NOT EXISTS sales (
    id          SERIAL PRIMARY KEY,
    -- DEFERRABLE INITIALLY DEFERRED: lets System Reset restore a backup
    -- even if a sales row is inserted before its matching product row
    -- (the check only runs at COMMIT, not on every INSERT).
    product_id  INTEGER REFERENCES products(id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED,
    quantity    INTEGER DEFAULT 1,
    unit_price  DECIMAL(10,2),
    total       DECIMAL(10,2),
    sale_date   DATE,
    deleted_at  TIMESTAMP,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sales_deleted_at ON sales(deleted_at);
CREATE INDEX IF NOT EXISTS idx_sales_product_id ON sales(product_id);

CREATE TABLE IF NOT EXISTS expenses (
    id            SERIAL PRIMARY KEY,
    category      VARCHAR(100),
    description   VARCHAR(255),
    amount        DECIMAL(10,2) DEFAULT 0.00,
    expense_date  DATE,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS deleted_expenses (
    id            SERIAL PRIMARY KEY,
    original_id   INTEGER,
    category      VARCHAR(50),
    description   TEXT,
    amount        DECIMAL(12,2),
    expense_date  DATE,
    deleted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS category_budgets (
    category       VARCHAR(50) PRIMARY KEY,
    monthly_limit  DECIMAL(12,2) NOT NULL DEFAULT 0.00
);

CREATE TABLE IF NOT EXISTS system_backups (
    id               SERIAL PRIMARY KEY,
    label            VARCHAR(255),
    tables_included  VARCHAR(255),
    total_rows       INTEGER DEFAULT 0,
    backup_data      TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id          SERIAL PRIMARY KEY,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    -- MySQL ENUM('admin','user') -> VARCHAR + CHECK constraint
    role        VARCHAR(10) NOT NULL DEFAULT 'user' CHECK (role IN ('admin','user')),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Seed data carried over from the original MySQL dump.
-- Passwords are already-bcrypt-hashed, so your existing accounts / logins
-- keep working exactly as before — nothing to re-hash.
INSERT INTO users (id, email, password, role, created_at) VALUES
(1, 'admin@profitlens.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '2026-03-25 09:26:34'),
(2, 'admin@email.com', '$2y$10$dYWE1t5MQZBBmoP26EXGV.yc3rBG0my3gJXL6cbZRenU4B5U.BBI.', 'user', '2026-03-25 09:44:07'),
(3, 'cram03namme@gmail.com', '$2y$10$wbKan/pYQZ01N7PNNvrqke/fW781NFafJBsL0cLvjDlvfXEdNNNJe', 'user', '2026-03-29 11:52:02')
ON CONFLICT (id) DO NOTHING;

-- Keep the auto-increment sequence ahead of the manually-inserted ids above,
-- otherwise the next new user could collide with id 1/2/3.
SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT MAX(id) FROM users));

-- NOTE: products / sales / expenses / category_budgets / deleted_expenses had
-- no rows in your export, so they're created empty — ready to use.
-- system_backups also starts empty; old MySQL-era backups (if any) aren't
-- carried over since they're just internal snapshots for the "Restore" button,
-- not real business data.

COMMIT;
