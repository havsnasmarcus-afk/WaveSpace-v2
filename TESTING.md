# Testing – WaveSpace

Two kinds of evidence: **automated** PHPUnit tests for the business logic and critical flows, and a
**manual** checklist run in the browser on desktop and phone width before every submission/demo.

## 1. Automated tests

### Run
```bash
# one-time setup (macOS)
brew install php composer postgresql@16
brew services start postgresql@16
createdb wavespace_test
psql -d wavespace_test -f schema.sql          # seeds 6 beaches, lockers, admin account

composer install
composer test:unit                            # pure logic, no database needed
composer test                                 # everything (needs wavespace_test)
```
`phpunit.xml` sets `WS_DB_DSN` to the local `wavespace_test` database, and `config/db.php` then ignores
`config/db.local.php` – so the tests can never touch the real FEUP database. Every database test runs in a
transaction that is rolled back, so the test database stays clean.

### What is covered
| File | Type | Covers (SPEC.md) |
|---|---|---|
| `tests/PricingTest.php` | unit, no DB | term discounts 1/3/12 months; card validation – Luhn, expiry, CVC, simulated decline (US2) |
| `tests/LockerTest.php` | DB | booking occupies a locker + 4-digit PIN; fully booked; revoke frees locker and removes PIN; cancel renewal keeps access; expired cancelled sub frees locker; other users cannot revoke (US1–US4) |
| `tests/BookingFlowTest.php` | DB, critical flows | browse → book → PIN + paid payment + map count −1; cancel → period ends → locker released; auto-renew charges and extends; DB rejects two active subscriptions on one locker (US1–US4) |
| `tests/StaffTest.php` | DB | NIF check digit; duplicate NIF; only admins grant admin; cannot deactivate yourself; create beach + add lockers; rented locker cannot be deleted (US6) |

### Latest run
2026-11-22 · macOS · PHP 8.6.0 · PostgreSQL 16 · PHPUnit 9.6.39 · local `wavespace_test` seeded from `schema.sql`

```
Pricing
 ✔ Monthly term has no discount
 ✔ Three month term is ten percent off
 ✔ Yearly term is twenty five percent off
 ✔ Valid card with spaces is accepted
 ✔ Card failing luhn check is rejected
 ✔ Expired card is rejected
 ✔ Bad cvc is rejected
 ✔ Simulated decline card

Locker
 ✔ Booking occupies locker and issues four digit pin
 ✔ Fully booked throws
 ✔ Revoke disables pin and frees locker
 ✔ Cancel renewal keeps access active
 ✔ Expired cancelled subscription frees locker
 ✔ Other user cannot revoke
 ✔ Card validation

Booking Flow
 ✔ Browse book and receive pin
 ✔ Cancel renewal then period ends
 ✔ Auto renew charges and extends
 ✔ Database rejects second active subscription on same locker

Staff
 ✔ Nif validation
 ✔ Create staff and reject duplicate nif
 ✔ Only admin can grant admin
 ✔ Cannot deactivate yourself
 ✔ Create beach with lockers and add more
 ✔ Rented locker cannot be deleted

Time: 00:01.651, Memory: 6.00 MB
OK (25 tests, 54 assertions)
```

## 2. Manual test checklist

Run on the **live URL**. Desktop = Chrome full width; Phone = DevTools device toolbar, iPhone SE (375 px).
Mark ✅ / ❌ and note the bug if it fails.

| # | Screen | Steps | Expected | Desktop | Phone |
|---|---|---|---|---|---|
| 1 | Map | Open home page | Pins on all 6 beaches with free counts; side list loads (skeleton while loading) | | |✅
| 2 | Map | Click Supertubos | Shows "Fully booked", no Book button | | |✅
| 3 | Map | Click Carcavelos | Capacity, free per size, prices; Book button | | |✅
| 4 | Auth | Book while logged out | Asked to log in / register first | | |✅
| 5 | Auth | Register with bad e-mail / 5-char password | Clear field errors, nothing saved | | |❌ 
| 6 | Auth | Register valid account | Logged in, header shows name | | |✅
| 7 | Auth | Log out, log in with wrong password | Error message, not logged in | | |✅ 
| 8 | Book | Choose longboard, 3 months | Price shows −10 % | | |✅ 
| 9 | Book | Pay with `4000 0000 0000 0002` | "Payment declined", no locker taken | | |✅ 
| 10 | Book | Pay with expired date / short CVC | Field errors | | |✅ 
| 11 | Book | Pay with `4242 4242 4242 4242` | Redirect to My locker, PIN visible; map free count dropped by 1 | | |✅
| 12 | My locker | New code | Different 4-digit code shown | | |✅
| 13 | My locker | Cancel auto-renewal | Status says access until renewal date; PIN still visible | | ✅|
| 14 | My locker | Renew now | New payment in history, renewal date +1 term | | |✅ 
| 15 | My locker | Update billing with invalid / valid card | Error / new last-4 shown | | |✅ 
| 16 | My locker | Send support request (empty, then filled) | Error / confirmation | | |✅ 
| 17 | My locker | Simulate failed payment → confirm | PIN gone, status revoked; locker free again on map | | |✅ 
| 18 | Staff | Log in as customer, open `staff.html` | No Staff tab; page shows "staff only" | | |✅ 
| 19 | Staff | Log in as admin → Overview | KPIs and charts show; numbers reflect the booking above | | |✅ 
| 20 | Staff | Lockers map → add 2 lockers to a beach | Counts update on map and on customer map | | |✅ 
| 21 | Staff | New beach → click map, fill form | New pin appears for customers | | |✅ 
| 22 | Staff | Team → add staff with invalid NIF | Error; valid NIF saves | | |✅ 
| 23 | All | Stop DB / wrong password in config (local only) | Friendly error, no PHP error page | | |✅ 

Tested by: Marcus Walter Date:22/11-26 URL: https://gnomo.fe.up.pt/~up202608623/

## 3. UX/UI review with the LLM

Prompt used for each screen (with a screenshot at desktop and 375 px):
> "Review this screen for visual hierarchy, colour contrast (WCAG AA), spacing, consistency with the other
> screens, accessibility (labels, focus, screen readers) and behaviour on a phone-sized screen. List concrete
> problems in order of impact and propose the CSS/HTML change for each."

| Screen | Problems found by the LLM | What I changed | Compared with Assignment 1 mockup |
|---|---|---|---|
| Map | | | |
| Book | | | |
| My locker | | | |
| Staff | | | |
