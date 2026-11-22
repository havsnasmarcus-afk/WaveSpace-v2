-- Adds the staff back office to an EXISTING WaveSpace database without wiping data.
-- Run once:  psql -h <host> -U <user> -d <database> -f migrations/001_staff.sql
-- (Fresh installs do not need this: schema.sql already contains everything.)
BEGIN;
SET search_path TO wavespace;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer','staff','admin'));
ALTER TABLE users ADD COLUMN IF NOT EXISTS nif    CHAR(9) UNIQUE CHECK (nif ~ '^[0-9]{9}$');
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone  VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;

-- First admin: admin@wavespace.pt / Staff2026!  (change the password in Staff > Team right after logging in)
INSERT INTO users (email, password_hash, full_name, nif, role)
VALUES ('admin@wavespace.pt', '$2y$10$0tndCNVyt5wRandvBkL16u831uEbXxJkwr1Gabn12irt6OShG74Wq', 'WaveSpace Admin', '123456789', 'admin')
ON CONFLICT (email) DO NOTHING;

COMMIT;
