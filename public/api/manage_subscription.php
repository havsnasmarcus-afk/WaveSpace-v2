<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

api_method('POST');
api_csrf();
$user = api_user();
$b = json_body();

$subId  = filter_var($b['subscription_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$action = (string) ($b['action'] ?? '');
$uid    = (int) $user['id'];
$extra  = [];

if ($subId <= 0 && $action !== 'support') {
    json_error('Missing subscription.', 422);
}

switch ($action) {
    case 'cancel_renewal':
        $ok  = LockerService::cancelRenewal(db(), $subId, $uid);
        $msg = 'Subscription cancelled. Your locker stays active until the end of the paid period.';
        break;
    case 'renew':
        $ok  = LockerService::renew(db(), $subId, $uid);
        $msg = 'Subscription renewed. Thank you!';
        break;
    case 'new_code':
        $pin = LockerService::newCode(db(), $subId, $uid);
        $ok  = $pin !== null;
        $msg = 'Your new access code is ready.';
        $extra = ['pin' => $pin];
        break;
    case 'simulate_failure':
        $ok  = LockerService::revoke(db(), $subId, $uid);
        $msg = 'Renewal payment failed (simulated). Your code was revoked and the locker released.';
        break;
    case 'update_card':
        $number = (string) ($b['card_number'] ?? '');
        $errors = LockerService::validateCard($number, trim((string) ($b['expiry'] ?? '')), trim((string) ($b['cvc'] ?? '')));
        if ($errors) {
            json_error('Please fix the highlighted problems.', 422, $errors);
        }
        $ok  = LockerService::updateCard(db(), $subId, $uid, $number);
        $msg = 'Billing method updated.';
        break;
    case 'support':
        $errors = LockerService::supportRequest(
            db(), $uid, $subId > 0 ? $subId : null, (string) ($b['topic'] ?? ''), (string) ($b['message'] ?? '')
        );
        if ($errors) {
            json_error('Please fix the highlighted problems.', 422, $errors);
        }
        $ok  = true;
        $msg = 'Request sent. We will get back to you soon.';
        break;
    default:
        json_error('Unknown action.', 400);
}

if (!$ok) {
    json_error('That subscription is not active or does not belong to you.', 404);
}
json_out(['message' => $msg] + $extra);
