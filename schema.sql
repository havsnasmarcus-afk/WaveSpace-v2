-- WaveSpace schema + demo data (PostgreSQL).
-- Run:  psql -h <host> -U <user> -d <database> -f schema.sql      (or paste into pgAdmin's Query Tool)
--
-- WARNING: this script is re-runnable and WIPES everything in the "wavespace" schema first (demo data only).
-- Tables live in their own schema so they cannot clash with other tables in a shared database.
-- If your account is NOT allowed to create schemas: delete the three lines DROP SCHEMA / CREATE SCHEMA / SET search_path,
-- and put  'schema' => ''  in config/db.local.php (the tables then go into your account's default schema).
BEGIN;
DROP SCHEMA IF EXISTS wavespace CASCADE;
CREATE SCHEMA wavespace;
SET search_path TO wavespace;

CREATE TABLE users (
    id            SERIAL PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(255) NOT NULL,
    role          VARCHAR(10)  NOT NULL DEFAULT 'customer' CHECK (role IN ('customer','staff','admin')),
    nif           CHAR(9)      UNIQUE CHECK (nif ~ '^[0-9]{9}$'),   -- Portuguese tax number (staff only)
    phone         VARCHAR(20),
    active        BOOLEAN      NOT NULL DEFAULT TRUE,                -- FALSE = cannot log in (staff who left)
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);

CREATE TABLE locations (
    id                    SERIAL PRIMARY KEY,
    name                  VARCHAR(120) NOT NULL,
    region                VARCHAR(80)  NOT NULL,
    latitude              NUMERIC(9,6) NOT NULL,
    longitude             NUMERIC(9,6) NOT NULL,
    shortboard_price_cents INTEGER NOT NULL CHECK (shortboard_price_cents > 0),
    longboard_price_cents  INTEGER NOT NULL CHECK (longboard_price_cents > 0)
);

CREATE TABLE lockers (
    id            SERIAL PRIMARY KEY,
    location_id   INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    label         VARCHAR(20) NOT NULL,
    size_category VARCHAR(12) NOT NULL CHECK (size_category IN ('shortboard','longboard')),
    status        VARCHAR(10) NOT NULL DEFAULT 'available' CHECK (status IN ('available','occupied')),
    UNIQUE (location_id, label)
);
CREATE INDEX idx_lockers_lookup ON lockers (location_id, size_category, status);

CREATE TABLE subscriptions (
    id                  SERIAL PRIMARY KEY,
    user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    locker_id           INTEGER NOT NULL REFERENCES lockers(id) ON DELETE RESTRICT,
    status              VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','revoked','ended')),
    access_pin          CHAR(4),
    term_months         SMALLINT NOT NULL DEFAULT 1 CHECK (term_months IN (1, 3, 12)),
    monthly_price_cents INTEGER NOT NULL,           -- list price per month when subscribed
    amount_cents        INTEGER NOT NULL,           -- what is charged per term (after the term discount)
    start_date          DATE NOT NULL DEFAULT CURRENT_DATE,
    renewal_date        DATE NOT NULL,
    auto_renew          BOOLEAN NOT NULL DEFAULT TRUE,
    card_last4          CHAR(4) NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
    CHECK ((status = 'active') = (access_pin IS NOT NULL))
);
CREATE INDEX idx_subscriptions_user ON subscriptions (user_id);
-- A locker can never have two active subscriptions, even if application logic fails
CREATE UNIQUE INDEX uq_active_subscription_per_locker ON subscriptions (locker_id) WHERE status = 'active';

CREATE TABLE payments (
    id              SERIAL PRIMARY KEY,
    subscription_id INTEGER NOT NULL REFERENCES subscriptions(id) ON DELETE CASCADE,
    amount_cents    INTEGER NOT NULL,
    status          VARCHAR(10) NOT NULL CHECK (status IN ('paid','failed')),
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE support_requests (
    id              SERIAL PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    subscription_id INTEGER REFERENCES subscriptions(id) ON DELETE SET NULL,
    topic           VARCHAR(60) NOT NULL,
    message         TEXT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Seed data (beaches, prices and free counts follow the design mockups) ----------------------
INSERT INTO locations (name, region, latitude, longitude, shortboard_price_cents, longboard_price_cents) VALUES
 ('Ribeira d''Ilhas',   'Ericeira',  38.984, -9.419, 1900, 2900),
 ('Supertubos',         'Peniche',   39.345, -9.360, 1900, 2900),
 ('Carcavelos',         'Cascais',   38.678, -9.337, 1900, 2900),
 ('Costa da Caparica',  'Almada',    38.640, -9.236, 1900, 2900),
 ('Tonel',              'Sagres',     37.012, -8.947, 1900, 2900),
 ('Arrifana',           'Aljezur',   37.292, -8.866, 1900, 2900);

-- name, shortboard lockers, longboard lockers, free shortboard, free longboard  (free totals: 6, 0, 11, 3, 9, 5)
CREATE TEMP TABLE seed_beach (name text, n_short int, n_long int, free_short int, free_long int);
INSERT INTO seed_beach VALUES
 ('Ribeira d''Ilhas', 14, 6, 4, 2),
 ('Supertubos',        9, 3, 0, 0),
 ('Carcavelos',       16, 8, 8, 3),
 ('Costa da Caparica',11, 5, 2, 1),
 ('Tonel',             8, 4, 6, 3),
 ('Arrifana',          7, 3, 4, 1);

INSERT INTO lockers (location_id, label, size_category, status)
SELECT l.id, g::text, 'shortboard', CASE WHEN g <= b.free_short THEN 'available' ELSE 'occupied' END
FROM seed_beach b JOIN locations l ON l.name = b.name, generate_series(1, b.n_short) g
UNION ALL
SELECT l.id, (b.n_short + g)::text, 'longboard', CASE WHEN g <= b.free_long THEN 'available' ELSE 'occupied' END
FROM seed_beach b JOIN locations l ON l.name = b.name, generate_series(1, b.n_long) g;

-- The occupied lockers belong to a placeholder "member" that cannot log in (its password hash never matches).
INSERT INTO users (email, password_hash, full_name)
VALUES ('demo-members@wavespace.invalid', '!', 'Existing members');

-- First staff account (admin). Log in with  admin@wavespace.pt / Staff2026!  and CHANGE THE PASSWORD in Staff > Team.
INSERT INTO users (email, password_hash, full_name, nif, role)
VALUES ('admin@wavespace.pt', '$2y$10$0tndCNVyt5wRandvBkL16u831uEbXxJkwr1Gabn12irt6OShG74Wq', 'WaveSpace Admin', '123456789', 'admin');

INSERT INTO subscriptions (user_id, locker_id, status, access_pin, term_months, monthly_price_cents, amount_cents,
                           renewal_date, auto_renew, card_last4)
SELECT (SELECT id FROM users WHERE email = 'demo-members@wavespace.invalid'),
       k.id, 'active', lpad((floor(random() * 10000))::int::text, 4, '0'), 1,
       CASE k.size_category WHEN 'shortboard' THEN l.shortboard_price_cents ELSE l.longboard_price_cents END,
       CASE k.size_category WHEN 'shortboard' THEN l.shortboard_price_cents ELSE l.longboard_price_cents END,
       CURRENT_DATE + (k.id % 27 + 1), TRUE, '0000'
FROM lockers k JOIN locations l ON l.id = k.location_id
WHERE k.status = 'occupied';

COMMIT;
