<?php
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';

// Staff back office. Every request needs a logged-in, active staff/admin account.
$method = api_method('GET', 'POST');
$actor  = api_staff();

if ($method === 'GET') {
    $view = (string) ($_GET['view'] ?? '');
    switch ($view) {
        case 'kpis':
            LockerService::processDue(db());
            json_out(StaffService::kpis(db()) + ['generated_at' => date('c')]);
            // no break: json_out exits
        case 'staff':
            json_out(['staff' => StaffService::listStaff(db()), 'me' => $actor]);
        case 'inventory':
            json_out(['locations' => StaffService::inventory(db())]);
        default:
            json_error('Unknown view.', 400);
    }
}

api_csrf();
$b = json_body();
$action = (string) ($b['action'] ?? '');
$id = filter_var($b['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

switch ($action) {
    case 'create_staff':
        $r = StaffService::createStaff(db(), $actor, $b);
        if ($r['errors']) {
            json_error('Please fix the highlighted problems.', 422, $r['errors']);
        }
        json_out(['message' => 'Staff member added. They can log in with the password you set.', 'id' => $r['id']], 201);

    case 'update_staff':
        $errors = StaffService::updateStaff(db(), $actor, $id, $b);
        if ($errors) {
            json_error('Please fix the highlighted problems.', 422, $errors);
        }
        json_out(['message' => 'Staff member updated.']);

    case 'create_location':
        $r = StaffService::createLocation(db(), $b);
        if ($r['errors']) {
            json_error('Please fix the highlighted problems.', 422, $r['errors']);
        }
        json_out(['message' => 'Beach added to the map.', 'id' => $r['id']], 201);

    case 'update_location':
        $errors = StaffService::updateLocation(db(), $id, $b);
        if ($errors) {
            json_error('Please fix the highlighted problems.', 422, $errors);
        }
        json_out(['message' => 'Beach updated.']);

    case 'add_lockers':
        $count = filter_var($b['count'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 200) {
            json_error('You can add between 1 and 200 lockers at a time.', 422);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $n = StaffService::addLockers($pdo, $id, (string) ($b['size'] ?? ''), $count);
            $pdo->commit();
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            if ($ex instanceof PDOException) {
                throw $ex;
            }
            json_error($ex->getMessage(), 422);
        }
        json_out(['message' => $n === 1 ? '1 locker added.' : "$n lockers added."], 201);

    case 'delete_locker':
        if (!StaffService::deleteLocker(db(), $id)) {
            json_error('Only free lockers that have never been rented can be removed.', 409);
        }
        json_out(['message' => 'Locker removed.']);

    default:
        json_error('Unknown action.', 400);
}
