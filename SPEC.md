# SPEC – WaveSpace (smart surf-locker rental)

Based on the user stories of Assignment 1. This file is the source of truth for what the MVP must do;
`CLAUDE.md` says how to build it. Each criterion is marked with the test that checks it
(**[A]** automated PHPUnit test, **[M]** manual check in `TESTING.md`).

## 1. Product
- **Audience:** local surfers and coastal commuters with no storage close to the beach.
- **Problem:** carrying boards every day is a hassle, surf shops have short opening hours, and there is no
  secure 24/7 storage next to the best spots.
- **Solution:** a web platform to find a beach, rent a locker on a monthly subscription, and open it 24/7
  with a 4-digit code.

## 2. MVP scope
**In:** accounts (register/login/logout) · beach map with real-time availability · booking + simulated card
payment · 4-digit access code · subscription management · support request · staff dashboard and inventory.

**Out (later):** real payment provider, real lock hardware, e-mail/SMS notifications, profile editing,
password reset, multiple languages, reviews.

## 3. User stories and acceptance criteria

### US1 – Find a locker (Must)
*As a surfer, I want to see available lockers per beach so that I can pick one close to my surf spot.*
- Map shows a pin for every beach; the pin shows how many lockers are free right now. [M]
- Selecting a beach shows total capacity and free units per size (shortboard / longboard) and the monthly price. [A `BookingFlowTest::testBrowseBookAndReceivePin`] [M]
- A beach (or size) with no free lockers shows **"Fully booked"** and cannot be booked. [A `LockerTest::testFullyBookedThrows`] [M]

### US2 – Subscribe and pay (Must)
*As a customer, I want to subscribe with my card so that my locker is reserved immediately.*
- Booking requires being logged in; a visitor can register or log in inside the flow. [M]
- Terms: 1 month (list price), 3 months (−10 %), 12 months (−25 %). [A `PricingTest`]
- Card is validated (Luhn, future MM/YY, 3–4 digit CVC). `4242 4242 4242 4242` succeeds,
  `4000 0000 0000 0002` is declined with a clear message. [A `PricingTest`, `LockerTest::testCardValidation`] [M]
- On success: the locker goes Available → Occupied, a subscription and a *paid* payment are stored,
  and the user lands on "My locker". [A `BookingFlowTest::testBrowseBookAndReceivePin`]
- Two people can never get the same locker (row lock + DB unique index). [A `BookingFlowTest::testDatabaseRejectsSecondActiveSubscriptionOnSameLocker`]

### US3 – Access code (Must)
*As a subscriber, I want to see my locker's PIN online so that I can open it at any time.*
- "My locker" shows the beach, locker number and a 4-digit PIN **only** while the subscription is active. [A `LockerTest::testBookingOccupiesLockerAndIssuesFourDigitPin`] [M]
- The user can generate a new code. [M]
- **Simulated failed payment:** a demo button immediately revokes the PIN, ends access and frees the locker. [A `LockerTest::testRevokeDisablesPinAndFreesLocker`] [M]
- Nobody can act on another user's subscription. [A `LockerTest::testOtherUserCannotRevoke`]

### US4 – Manage subscription (Should)
*As a subscriber, I want to manage my plan online so that I can stop renewal or change my card.*
- One-click cancel auto-renewal; access stays active until the renewal date, then the locker is released. [A `LockerTest::testCancelRenewalKeepsAccessActive`, `BookingFlowTest::testCancelRenewalThenPeriodEnds`]
- Renew now: pay the next term early – adds a payment, extends the renewal date by one term and switches auto-renewal back on. [M]
- On the renewal date an auto-renewing subscription is charged again and extended by one term. [A `BookingFlowTest::testAutoRenewChargesAndExtends`]
- Update the billing card (validated, only last 4 digits stored). [M]
- Payment history lists every charge with date, amount and status. [M]
- Send a support request (topic + message) linked to the locker. [M]

### US5 – Staff dashboard (Should)
*As an operator, I want to see occupancy and revenue so that I can track the business.*
- Only `staff`/`admin` accounts see the Staff tab; the API returns 403 for anyone else. [M]
- Overview: occupancy % overall and per beach, MRR, active subscribers, revenue last 30 days vs previous 30,
  new subscriptions, failed payments, pending cancellations, support requests. [M]

### US6 – Manage inventory and team (Could – built after the core)
*As an admin, I want to manage beaches, lockers and staff so that the network can grow without SQL.*
- Create a beach on the map with prices and starting locker counts; add lockers; edit a beach. [A `StaffTest::testCreateBeachWithLockersAndAddMore`]
- A locker that has ever been rented cannot be deleted. [A `StaffTest::testRentedLockerCannotBeDeleted`]
- Create/edit staff with a valid Portuguese NIF (check digit), unique e-mail and NIF. [A `StaffTest::testNifValidation`, `testCreateStaffAndRejectDuplicateNif`]
- Only admins can grant the admin role; nobody can deactivate or demote themselves; inactive accounts cannot log in. [A `StaffTest::testOnlyAdminCanGrantAdmin`, `testCannotDeactivateYourself`]

## 4. Data model (see `schema.sql`)
| Table | Primary key | Foreign keys | Purpose |
|---|---|---|---|
| `users` | `id` | – | customers, staff and admins (`role`), unique e-mail, optional unique NIF |
| `locations` | `id` | – | beaches with coordinates and monthly prices (cents) per board size |
| `lockers` | `id` | `location_id → locations` (CASCADE) | one physical locker, size, `available`/`occupied`; label unique per beach |
| `subscriptions` | `id` | `user_id → users` (CASCADE), `locker_id → lockers` (RESTRICT) | rental: status, PIN, term, prices, renewal date, auto-renew, card last 4 |
| `payments` | `id` | `subscription_id → subscriptions` (CASCADE) | every charge, `paid`/`failed` |
| `support_requests` | `id` | `user_id → users` (CASCADE), `subscription_id → subscriptions` (SET NULL) | help form messages |

Key invariants enforced by the database: PIN present ⇔ status `active`; at most one `active`
subscription per locker (partial unique index); prices > 0; term ∈ {1, 3, 12}.

## 5. Non-functional
- Works on a 375 px wide phone and on desktop. Text contrast ≥ 4.5:1. Keyboard usable.
- All errors shown to the user in plain language; the API never returns an HTML error page.
- Publicly reachable at the FEUP address and usable by other applications through the JSON API.
