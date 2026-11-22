<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

api_method('GET');
$user = api_user();
LockerService::processDue(db());

$st = db()->prepare("SELECT s.id, s.status, s.access_pin, s.term_months, s.monthly_price_cents, s.amount_cents,
                            s.start_date, s.renewal_date, s.auto_renew, s.card_last4,
                            k.label AS locker_label, k.size_category, l.name AS location_name, l.region
                     FROM subscriptions s
                     JOIN lockers k ON k.id = s.locker_id
                     JOIN locations l ON l.id = k.location_id
                     WHERE s.user_id = :u
                     ORDER BY (s.status = 'active') DESC, s.created_at DESC, s.id DESC");
$st->execute([':u' => $user['id']]);
$subs = array_map(static fn(array $s): array => [
    'id'           => (int) $s['id'],
    'status'       => $s['status'],
    'location'     => $s['location_name'],
    'region'       => $s['region'],
    'locker'       => $s['locker_label'],
    'size'         => $s['size_category'],
    'term_months'  => (int) $s['term_months'],
    'price_cents'  => (int) $s['monthly_price_cents'],
    'amount_cents' => (int) $s['amount_cents'],
    'start_date'   => $s['start_date'],
    'renewal_date' => $s['renewal_date'],
    'auto_renew'   => (bool) $s['auto_renew'],
    'card_last4'   => $s['card_last4'],
    // The code is only ever sent while the subscription is active (SPEC 3.3)
    'pin'          => $s['status'] === 'active' ? $s['access_pin'] : null,
], $st->fetchAll());

$st = db()->prepare("SELECT p.id, p.amount_cents, p.status, p.created_at, l.name AS location_name
                     FROM payments p
                     JOIN subscriptions s ON s.id = p.subscription_id
                     JOIN lockers k ON k.id = s.locker_id
                     JOIN locations l ON l.id = k.location_id
                     WHERE s.user_id = :u ORDER BY p.created_at DESC, p.id DESC LIMIT 30");
$st->execute([':u' => $user['id']]);
$payments = array_map(static fn(array $p): array => [
    'id' => (int) $p['id'], 'amount_cents' => (int) $p['amount_cents'], 'status' => $p['status'],
    'created_at' => $p['created_at'], 'location' => $p['location_name'],
], $st->fetchAll());

json_out(['user' => $user, 'subscriptions' => $subs, 'payments' => $payments]);
