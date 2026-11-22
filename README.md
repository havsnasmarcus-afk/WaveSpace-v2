# WaveSpace

Surf-locker rental: map of beaches, booking with a simulated card payment, 4-digit access code in "My locker".
PHP 7.4+ (PDO pgsql) · PostgreSQL · Tailwind (CDN) + vanilla JS.

## Deploy on gnomo.fe.up.pt
1. Copy `config/db.local.example.php` to `config/db.local.php` and fill in YOUR database login
   (`dsn` = `pgsql:host=...;dbname=mesg2604`, `user`, `pass`). Never commit/share this file. Keep `'debug' => true` only while setting up.
2. Run `schema.sql` on the database (psql or the school's SQL tool). WARNING: it drops and recreates the `wavespace` schema (all its data).
3. Upload the CONTENTS of `public/` into `public_html/` (so `index.html`, `api/`, `js/`, `css/` sit there) and `config/` + `src/` next to or inside it (`api/` finds `../../config` or `../config`).
4. Open `https://gnomo.fe.up.pt/~USER/api/check.php`. Every line should say OK. Then DELETE `check.php`, and set `'debug' => false`.
   Off campus the database may only be reachable through the FEUP VPN.

## Test cards
`4242 4242 4242 4242` succeeds, `4000 0000 0000 0002` is declined (any future expiry, any CVC).

## Tests
`composer install`, then `composer test:unit` (no database) or `composer test` (needs a local `wavespace_test`
database loaded with `schema.sql`). Full instructions, coverage and the manual checklist: see `TESTING.md`.

## Project docs
- `SPEC.md` – user stories and acceptance criteria (from Assignment 1)
- `CLAUDE.md` – architecture, folder structure and rules for the LLM agent
- `TESTING.md` – automated + manual testing evidence and UX review log

## Staff area (`staff.html`)
A **Staff** tab appears in the header only for accounts with role `staff` or `admin` (the API returns 403 for everyone else).
- **Overview**: occupancy, monthly recurring revenue, 30-day revenue vs. the previous 30 days, new subscriptions,
  failed payments, pending cancellations, support requests, daily revenue chart, occupancy per beach.
- **Lockers map**: real map (Leaflet + OpenStreetMap/CARTO tiles) with every beach at its coordinates. Click a pin to see
  each locker (free / rented, by whom, renewal date), add lockers, remove never-rented free lockers, or edit the beach.
  "New beach" → click the map to place it, set prices and starting locker counts.
- **Team**: add / edit staff (name, email, NIF with check-digit validation, phone, role, password, active).
  Only admins can create or edit admins; nobody can deactivate or demote themselves. Inactive accounts cannot log in.

First login: `admin@wavespace.pt` with the initial password set in `schema.sql`. **Change it right away** (Staff → Team → Edit).
Existing database? Run `migrations/001_staff.sql` instead of `schema.sql` – it adds the new columns and the admin account without wiping data.
