<?php
declare(strict_types=1);

/** Staff back office: operational KPIs, staff accounts and locker/beach inventory. Shared by the API and PHPUnit tests. */
final class StaffService
{
    public const STAFF_ROLES = ['staff', 'admin'];

    public static function isStaff(?array $user): bool
    {
        return $user !== null && in_array($user['role'] ?? '', self::STAFF_ROLES, true);
    }

    /** Portuguese NIF: 9 digits, first digit in an allowed range, mod-11 check digit. */
    public static function validNif(string $nif): bool
    {
        if (!preg_match('/^[1235689]\d{8}$/', $nif) && !preg_match('/^(45|70|71|72|74|75|77|79|90|91|98|99)\d{7}$/', $nif)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }
        $check = 11 - ($sum % 11);
        if ($check >= 10) {
            $check = 0;
        }
        return $check === (int) $nif[8];
    }

    // ---------------------------------------------------------------- KPIs

    public static function kpis(PDO $pdo): array
    {
        $k = $pdo->query("SELECT
                (SELECT COUNT(*) FROM lockers) AS lockers,
                (SELECT COUNT(*) FROM lockers WHERE status = 'occupied') AS occupied,
                (SELECT COUNT(*) FROM locations) AS beaches,
                (SELECT COUNT(*) FROM subscriptions WHERE status = 'active') AS active_subs,
                (SELECT COALESCE(SUM(amount_cents::numeric / term_months), 0) FROM subscriptions WHERE status = 'active') AS mrr_cents,
                (SELECT COUNT(*) FROM subscriptions WHERE status = 'active' AND NOT auto_renew) AS pending_cancellations,
                (SELECT COUNT(*) FROM subscriptions WHERE created_at >= NOW() - INTERVAL '30 days') AS new_subs_30d,
                (SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE status = 'paid' AND created_at >= NOW() - INTERVAL '30 days') AS revenue_30d,
                (SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE status = 'paid'
                    AND created_at >= NOW() - INTERVAL '60 days' AND created_at < NOW() - INTERVAL '30 days') AS revenue_prev_30d,
                (SELECT COUNT(*) FROM payments WHERE status = 'failed' AND created_at >= NOW() - INTERVAL '30 days') AS failed_payments_30d,
                (SELECT COUNT(*) FROM payments WHERE created_at >= NOW() - INTERVAL '30 days') AS payments_30d,
                (SELECT COUNT(*) FROM support_requests WHERE created_at >= NOW() - INTERVAL '7 days') AS support_7d,
                (SELECT COUNT(*) FROM users WHERE role = 'customer' AND email NOT LIKE '%.invalid') AS customers,
                (SELECT COUNT(*) FROM users WHERE role IN ('staff','admin') AND active) AS staff")->fetch();
        $k = array_map(static fn($v) => (int) round((float) $v), $k);

        $beaches = $pdo->query("SELECT l.id, l.name, l.region,
                       COUNT(k.id) AS capacity,
                       COUNT(k.id) FILTER (WHERE k.status = 'occupied') AS occupied,
                       COALESCE((SELECT SUM(p.amount_cents) FROM payments p
                                 JOIN subscriptions s ON s.id = p.subscription_id
                                 JOIN lockers k2 ON k2.id = s.locker_id
                                 WHERE k2.location_id = l.id AND p.status = 'paid'
                                   AND p.created_at >= NOW() - INTERVAL '30 days'), 0) AS revenue_30d
                FROM locations l LEFT JOIN lockers k ON k.location_id = l.id
                GROUP BY l.id ORDER BY l.name")->fetchAll();
        $beaches = array_map(static fn(array $b): array => [
            'id' => (int) $b['id'], 'name' => $b['name'], 'region' => $b['region'],
            'capacity' => (int) $b['capacity'], 'occupied' => (int) $b['occupied'], 'revenue_30d' => (int) $b['revenue_30d'],
        ], $beaches);

        // Paid revenue per day for the last 30 days (days without payments come back as 0)
        $daily = $pdo->query("SELECT d::date AS day, COALESCE(SUM(p.amount_cents), 0) AS cents
                FROM generate_series(CURRENT_DATE - 29, CURRENT_DATE, INTERVAL '1 day') d
                LEFT JOIN payments p ON p.status = 'paid' AND p.created_at::date = d::date
                GROUP BY d ORDER BY d")->fetchAll();
        $daily = array_map(static fn(array $r): array => ['day' => $r['day'], 'cents' => (int) $r['cents']], $daily);

        return ['totals' => $k, 'beaches' => $beaches, 'daily_revenue' => $daily];
    }

    // ---------------------------------------------------------------- Staff accounts

    public static function listStaff(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT id, email, full_name, nif, phone, role, active, created_at
                             FROM users WHERE role IN ('staff','admin') ORDER BY active DESC, full_name")->fetchAll();
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'email' => $r['email'], 'full_name' => $r['full_name'], 'nif' => $r['nif'],
            'phone' => $r['phone'], 'role' => $r['role'], 'active' => (bool) $r['active'], 'created_at' => $r['created_at'],
        ], $rows);
    }

    /** @return string[] validation errors for the editable staff fields (normalises $in in place) */
    private static function validateStaffInput(array &$in, array $actor, bool $isNew): array
    {
        $errors = [];
        $in['full_name'] = trim((string) ($in['full_name'] ?? ''));
        $in['email']     = strtolower(trim((string) ($in['email'] ?? '')));
        $in['nif']       = preg_replace('/\s+/', '', (string) ($in['nif'] ?? '')) ?? '';
        $in['phone']     = trim((string) ($in['phone'] ?? ''));
        $in['role']      = (string) ($in['role'] ?? 'staff');
        $in['password']  = (string) ($in['password'] ?? '');

        if ($in['full_name'] === '' || strlen($in['full_name']) > 120) {
            $errors[] = 'Please enter a name (max 120 characters).';
        }
        if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!self::validNif($in['nif'])) {
            $errors[] = 'NIF must be a valid 9-digit Portuguese tax number.';
        }
        if ($in['phone'] !== '' && !preg_match('/^\+?[0-9 ]{9,20}$/', $in['phone'])) {
            $errors[] = 'Phone must contain 9–20 digits (optionally starting with +).';
        }
        if (!in_array($in['role'], self::STAFF_ROLES, true)) {
            $errors[] = 'Role must be staff or admin.';
        } elseif ($in['role'] === 'admin' && $actor['role'] !== 'admin') {
            $errors[] = 'Only an admin can give someone the admin role.';
        }
        if (($isNew || $in['password'] !== '') && strlen($in['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        return $errors;
    }

    /** @return array{errors: string[], id?: int} */
    public static function createStaff(PDO $pdo, array $actor, array $in): array
    {
        $errors = self::validateStaffInput($in, $actor, true);
        if (!$errors) {
            $errors = self::uniqueErrors($pdo, $in['email'], $in['nif'], 0);
        }
        if ($errors) {
            return ['errors' => $errors];
        }
        $st = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, nif, phone, role)
                             VALUES (:e, :h, :n, :nif, :p, :r) RETURNING id');
        $st->execute([
            ':e' => $in['email'], ':h' => password_hash($in['password'], PASSWORD_DEFAULT), ':n' => $in['full_name'],
            ':nif' => $in['nif'], ':p' => $in['phone'] !== '' ? $in['phone'] : null, ':r' => $in['role'],
        ]);
        return ['errors' => [], 'id' => (int) $st->fetchColumn()];
    }

    /** @return string[] validation errors (empty = saved) */
    public static function updateStaff(PDO $pdo, array $actor, int $id, array $in): array
    {
        $st = $pdo->prepare("SELECT id, role, active FROM users WHERE id = :id AND role IN ('staff','admin')");
        $st->execute([':id' => $id]);
        $target = $st->fetch();
        if (!$target) {
            return ['That staff member does not exist.'];
        }
        if ($target['role'] === 'admin' && $actor['role'] !== 'admin') {
            return ['Only an admin can edit an admin account.'];
        }
        $active = filter_var($in['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $errors = self::validateStaffInput($in, $actor, false);
        if ($id === (int) $actor['id'] && !$active) {
            $errors[] = 'You cannot deactivate your own account.';
        }
        if ($id === (int) $actor['id'] && $in['role'] !== $actor['role']) {
            $errors[] = 'You cannot change your own role.';
        }
        if (!$errors) {
            $errors = self::uniqueErrors($pdo, $in['email'], $in['nif'], $id);
        }
        if ($errors) {
            return $errors;
        }
        $sql = 'UPDATE users SET email = :e, full_name = :n, nif = :nif, phone = :p, role = :r, active = :a'
             . ($in['password'] !== '' ? ', password_hash = :h' : '') . ' WHERE id = :id';
        $st = $pdo->prepare($sql);
        $st->bindValue(':e', $in['email']);
        $st->bindValue(':n', $in['full_name']);
        $st->bindValue(':nif', $in['nif']);
        $st->bindValue(':p', $in['phone'] !== '' ? $in['phone'] : null);
        $st->bindValue(':r', $in['role']);
        $st->bindValue(':a', $active, PDO::PARAM_BOOL);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        if ($in['password'] !== '') {
            $st->bindValue(':h', password_hash($in['password'], PASSWORD_DEFAULT));
        }
        $st->execute();
        return [];
    }

    private static function uniqueErrors(PDO $pdo, string $email, string $nif, int $exceptId): array
    {
        $errors = [];
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = :e AND id <> :id');
        $st->execute([':e' => $email, ':id' => $exceptId]);
        if ($st->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
        $st = $pdo->prepare('SELECT 1 FROM users WHERE nif = :n AND id <> :id');
        $st->execute([':n' => $nif, ':id' => $exceptId]);
        if ($st->fetch()) {
            $errors[] = 'Another account already uses this NIF.';
        }
        return $errors;
    }

    // ---------------------------------------------------------------- Beaches & lockers

    /** Every beach with its individual lockers (status, current member) for the staff map. */
    public static function inventory(PDO $pdo): array
    {
        $locations = LockerService::availability($pdo);
        $rows = $pdo->query("SELECT k.id, k.location_id, k.label, k.size_category, k.status,
                                    u.full_name AS member, s.renewal_date,
                                    EXISTS (SELECT 1 FROM subscriptions x WHERE x.locker_id = k.id) AS has_history
                             FROM lockers k
                             LEFT JOIN subscriptions s ON s.locker_id = k.id AND s.status = 'active'
                             LEFT JOIN users u ON u.id = s.user_id
                             ORDER BY k.location_id, (CASE WHEN k.label ~ '^[0-9]+$' THEN k.label::int END), k.label")->fetchAll();
        $byLoc = [];
        foreach ($rows as $r) {
            $byLoc[(int) $r['location_id']][] = [
                'id' => (int) $r['id'], 'label' => $r['label'], 'size' => $r['size_category'], 'status' => $r['status'],
                'member' => $r['member'], 'renewal_date' => $r['renewal_date'],
                'deletable' => $r['status'] === 'available' && !$r['has_history'],
            ];
        }
        foreach ($locations as &$l) {
            $l['lockers'] = $byLoc[(int) $l['id']] ?? [];
        }
        return $locations;
    }

    /** @return string[] */
    private static function validateLocation(array &$in): array
    {
        $errors = [];
        $in['name']   = trim((string) ($in['name'] ?? ''));
        $in['region'] = trim((string) ($in['region'] ?? ''));
        $in['lat']    = filter_var($in['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $in['lng']    = filter_var($in['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $in['short']  = (int) round((float) ($in['shortboard_price'] ?? 0) * 100);
        $in['long']   = (int) round((float) ($in['longboard_price'] ?? 0) * 100);
        if ($in['name'] === '' || strlen($in['name']) > 120) {
            $errors[] = 'Please enter the beach name (max 120 characters).';
        }
        if ($in['region'] === '' || strlen($in['region']) > 80) {
            $errors[] = 'Please enter the region (max 80 characters).';
        }
        if ($in['lat'] === false || $in['lng'] === false || abs($in['lat']) > 90 || abs($in['lng']) > 180) {
            $errors[] = 'Click the map to place the beach.';
        }
        if ($in['short'] <= 0 || $in['long'] <= 0 || $in['short'] > 100000 || $in['long'] > 100000) {
            $errors[] = 'Monthly prices must be between €0.01 and €1000.';
        }
        return $errors;
    }

    /** @return array{errors: string[], id?: int} */
    public static function createLocation(PDO $pdo, array $in): array
    {
        $errors = self::validateLocation($in);
        $nShort = (int) ($in['shortboard_count'] ?? 0);
        $nLong  = (int) ($in['longboard_count'] ?? 0);
        if ($nShort < 0 || $nLong < 0 || $nShort > 200 || $nLong > 200) {
            $errors[] = 'Locker counts must be between 0 and 200.';
        }
        if ($errors) {
            return ['errors' => $errors];
        }
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare('INSERT INTO locations (name, region, latitude, longitude, shortboard_price_cents, longboard_price_cents)
                                 VALUES (:n, :r, :lat, :lng, :s, :l) RETURNING id');
            $st->execute([':n' => $in['name'], ':r' => $in['region'], ':lat' => round($in['lat'], 6), ':lng' => round($in['lng'], 6),
                          ':s' => $in['short'], ':l' => $in['long']]);
            $id = (int) $st->fetchColumn();
            self::addLockers($pdo, $id, 'shortboard', $nShort);
            self::addLockers($pdo, $id, 'longboard', $nLong);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $t) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
        return ['errors' => [], 'id' => $id];
    }

    /** @return string[] */
    public static function updateLocation(PDO $pdo, int $id, array $in): array
    {
        $errors = self::validateLocation($in);
        if ($errors) {
            return $errors;
        }
        $st = $pdo->prepare('UPDATE locations SET name = :n, region = :r, latitude = :lat, longitude = :lng,
                                    shortboard_price_cents = :s, longboard_price_cents = :l WHERE id = :id');
        $st->execute([':n' => $in['name'], ':r' => $in['region'], ':lat' => round($in['lat'], 6), ':lng' => round($in['lng'], 6),
                      ':s' => $in['short'], ':l' => $in['long'], ':id' => $id]);
        return $st->rowCount() > 0 ? [] : ['That beach does not exist.'];
    }

    /** Adds $count lockers numbered after the highest existing numeric label. Returns the number added. */
    public static function addLockers(PDO $pdo, int $locationId, string $size, int $count): int
    {
        if (!in_array($size, LockerService::SIZES, true)) {
            throw new RuntimeException('Invalid board size.');
        }
        if ($count < 0 || $count > 200) {
            throw new RuntimeException('You can add between 1 and 200 lockers at a time.');
        }
        if ($count === 0) {
            return 0;
        }
        $pdo->prepare('SELECT id FROM locations WHERE id = :id FOR UPDATE')->execute([':id' => $locationId]);
        $st = $pdo->prepare("INSERT INTO lockers (location_id, label, size_category, status)
                             SELECT CAST(:loc AS int), (base.n + g)::text, CAST(:size AS varchar), 'available'
                             FROM (SELECT COALESCE(MAX(CASE WHEN label ~ '^[0-9]{1,9}$' THEN label::int END), 0) AS n
                                   FROM lockers WHERE location_id = CAST(:loc2 AS int)) base,
                                  generate_series(1, CAST(:cnt AS int)) g");
        $st->bindValue(':loc', $locationId, PDO::PARAM_INT);
        $st->bindValue(':loc2', $locationId, PDO::PARAM_INT);
        $st->bindValue(':size', $size);
        $st->bindValue(':cnt', $count, PDO::PARAM_INT);
        $st->execute();
        return $st->rowCount();
    }

    /** Removes a locker that is free and was never subscribed (keeps billing history intact). */
    public static function deleteLocker(PDO $pdo, int $lockerId): bool
    {
        $st = $pdo->prepare("DELETE FROM lockers k WHERE k.id = :id AND k.status = 'available'
                             AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.locker_id = k.id)");
        $st->execute([':id' => $lockerId]);
        return $st->rowCount() > 0;
    }
}
