<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

api_method('POST');
api_csrf();
$user = api_user();
$b = json_body();

$locationId = filter_var($b['location_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$term       = filter_var($b['term'] ?? 1, FILTER_VALIDATE_INT) ?: 0;
$size       = (string) ($b['size'] ?? '');
$name       = trim((string) ($b['card_name'] ?? ''));
$number     = (string) ($b['card_number'] ?? '');
$expiry     = trim((string) ($b['expiry'] ?? ''));
$cvc        = trim((string) ($b['cvc'] ?? ''));

$errors = [];
if ($locationId <= 0) {
    $errors[] = 'Please choose a beach.';
}
if (!in_array($size, LockerService::SIZES, true)) {
    $errors[] = 'Please choose a locker size.';
}
if (!isset(LockerService::TERMS[$term])) {
    $errors[] = 'Please choose a subscription term.';
}
if ($name === '' || strlen($name) > 120) {
    $errors[] = 'Please enter the name on the card.';
}
$errors = array_merge($errors, LockerService::validateCard($number, $expiry, $cvc));
if ($errors) {
    json_error('Please fix the highlighted problems.', 422, $errors);
}

try {
    // One atomic transaction: lock locker -> occupied -> subscription + 4-digit PIN -> first payment
    $r = LockerService::book(db(), (int) $user['id'], $locationId, $size, $term, $number);
} catch (RuntimeException $ex) {
    if ($ex->getMessage() === 'Fully booked') {
        json_error('Sorry, that locker size just became fully booked.', 409, ['Sorry, that locker size just became fully booked.']);
    }
    json_error($ex->getMessage(), 422, [$ex->getMessage()]);
}
json_out(['subscription_id' => $r['subscription_id']], 201);
