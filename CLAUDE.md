# WaveSpace – Project Rules for the LLM agent

Read this file and `SPEC.md` before every task. Every change must satisfy the acceptance criteria in `SPEC.md`.

## 1. Tech stack (fixed – do not introduce others)
- **Backend:** PHP 7.4+ (Apache on gnomo.fe.up.pt), PDO with `pdo_pgsql`. No PHP frameworks, no Composer runtime dependencies.
- **Database:** PostgreSQL. All tables live in the `wavespace` schema.
- **Frontend:** static HTML pages + vanilla JavaScript (ES modules) + Tailwind CSS (CDN). Leaflet for maps.
- **Testing:** PHPUnit 9 (dev-only, via Composer) + the manual checklist in `TESTING.md`.

## 2. Architecture
Three layers, one direction of dependency: **page → JS → JSON API → service → database**.

```text
WaveSpace-v2/
├── CLAUDE.md               # these rules
├── SPEC.md                 # user stories + acceptance criteria (source of truth)
├── TESTING.md              # how to run tests + manual test checklist + UX review log
├── schema.sql              # tables, constraints, indexes, seed data (re-runnable, wipes schema)
├── migrations/             # additive changes for an existing database (never wipe data)
├── config/
│   ├── db.php              # PDO connection, reads db.local.php (or WS_DB_* env vars)
│   ├── db.local.example.php# template – copy to db.local.php (git-ignored, holds secrets)
│   ├── app.php             # session + current_user() + CSRF token
│   └── api.php             # JSON helpers: json_out, json_error, api_user, api_staff, api_csrf
├── src/                    # ALL business logic, no HTTP code here
│   ├── LockerService.php   # availability, booking, PIN, renewals, revoke, card validation
│   └── StaffService.php    # KPIs, staff accounts, beaches/lockers inventory, NIF validation
├── public/                 # the only folder served by Apache (→ public_html/)
│   ├── index.html          # Map: beaches + free lockers
│   ├── booking.html        # Book: size, term, simulated card payment
│   ├── account.html        # My locker: PIN, renewal, billing, payments, support
│   ├── staff.html          # Staff back office (staff/admin only)
│   ├── api/*.php           # thin endpoints: validate method/CSRF/auth → call src/ → JSON
│   ├── js/*.js             # one module per page + shared api.js, ui.js, nav.js, auth.js
│   └── css/app.css
└── tests/                  # PHPUnit: PricingTest (no DB), LockerTest, BookingFlowTest, StaffTest
```

**Evolution note:** the first plan (Assignment 1/v1) used server-rendered `index.php`, `checkout.php`,
`dashboard.php`, `admin.php`. It was changed to static HTML + a JSON API so the same API can be reused
by other applications in the next assignments. The layering rules below did not change.

## 3. Rules for endpoints (`public/api/*.php`)
- Start with `require __DIR__ . '/_boot.php';` then `api_method(...)`.
- Every state-changing request calls `api_csrf()`. Logged-in features call `api_user()`; staff features call `api_staff()`.
- Endpoints contain **no SQL beyond trivial reads** – put logic in `src/` so it can be unit-tested.
- Always answer with `json_out()` / `json_error()`; errors are `{error, errors[]}` with a correct HTTP status
  (400 bad input, 401 not logged in, 403 forbidden/CSRF, 404, 409 conflict e.g. fully booked, 422 validation).

## 4. Database conventions
- `snake_case` names; every table has `id SERIAL PRIMARY KEY`; every FK has an explicit `ON DELETE` rule.
- Money is stored as integer **cents**. Dates as `DATE`, timestamps as `TIMESTAMP`.
- Business invariants are enforced in the database too (CHECK constraints, partial unique index on active subscriptions).
- **Always** PDO prepared statements with bound parameters for anything coming from the user.
- Multi-step writes (booking, revoke, renewals) run in a transaction and lock rows with `FOR UPDATE`.
- Schema changes: update `schema.sql` **and** add a numbered file in `migrations/`.

## 5. Frontend / UX rules
- Mobile first: must work at 375 px wide with no horizontal scroll; use Tailwind `sm:`/`lg:` breakpoints.
- Every form shows server validation errors next to the form, and a loading state while saving.
- Accessibility: real `<label>`s, `aria-live` for dynamic content, visible focus, text contrast ≥ 4.5:1.
- Escape all user data with `esc()` before inserting into HTML.
- Reuse the shared components in `ui.js` and classes in `app.css` (`card`, `btn`, `btn-outline`, …) – keep it consistent.

## 6. Security rules
- Never commit `config/db.local.php` or any password. Never print credentials.
- `'debug' => false` in production. Remove `install.php`, `migrate.php`, `check.php` from the server after use.
- Passwords: `password_hash` / `password_verify` only. Sessions: httponly, SameSite=Lax cookie.

## 7. Workflow for the agent
1. Restate which `SPEC.md` story/criterion the change serves. If none, ask before building it (stay in MVP scope).
2. Make the smallest change that works; follow the folder structure above.
3. Add or update a PHPUnit test for any change in `src/`.
4. Run `composer test` and report the real result. Never claim tests pass without running them.
5. List what the human must check manually in the browser (desktop + phone width).
