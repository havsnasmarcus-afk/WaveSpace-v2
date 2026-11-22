<?php
declare(strict_types=1);

/** Core inventory/subscription logic. Shared by the API and PHPUnit tests. */
final class LockerService
{
    public const SIZES = ['shortboard', 'longboard'];
    /** subscription term in months => discount in percent */
    public const TERMS = [1 => 0, 3 => 10, 12 => 25];
    public const TOPICS = ["Locker won't open", "Access code isn't working", 'Billing question', 'Something else'];
    public const DECLINED_CARD = '4000000000000002'; // simulated decline

    /** Amount charged per term, after the term discount. */
    public static function termPrice(int $monthlyCents, int $term): int
    {
        return (int) round($monthlyCents * $term * (100 - self::TERMS[$term]) / 100);
    }

    /** @return string[] validation errors (empty = valid) */
    public static function validateCard(string $number, string $expiry, string $cvc): array
    {
        $errors = [];
        $number = preg_replace('/\s+/', '', $number) ?? '';
        if (!preg_match('/^\d{13,19}$/', $number) || !self::luhn($number)) {
            $errors[] = 'Card number is not valid.';
        }
        if (!preg_match('#^(0[1-9]|1[0-2])\s*/\s*(\d{2})$#', $expiry, $m)
            || (int) ('20' . $m[2]) * 100 + (int) $m[1] < (int) date('Ym')) {
            $errors[] = 'Expiry must be a future date in MM/YY format.';
        }
        if (!preg_match('/^\d{3,4}$/', $cvc)) {
            $errors[] = 'CVC must be 3 or 4 digits.';
        }
        if ($errors === [] && $number === self::DECLINED_CARD) {
            $errors[] = 'Payment declined by the (simulated) card processor.';
        }
        return $errors;
    }

    private static function luhn(string $n): bool
    {
        $sum = 0;
        foreach (array_reverse(str_split($n)) as $i => $d) {
            $d = (int) $d;
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }
        return $sum % 10 === 0;
    }

    private static function newPin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /** Locations with real-time capacity/availability (total and per board size), in map order. */
    public static function availability(PDO $pdo): array
    {
        $sql = "SELECT l.id, l.name, l.region, l.latitude, l.longitude,
                       l.shortboard_price_cents, l.longboard_price_cents,
                       COUNT(k.id) AS capacity,
                       COUNT(k.id) FILTER (WHERE k.status = 'available') AS available,
                       COUNT(k.id) FILTER (WHERE k.size_category = 'shortboard') AS short_capacity,
                       COUNT(k.id) FILTER (WHERE k.size_category = 'shortboard' AND k.status = 'available') AS short_available,
                       COUNT(k.id) FILTER (WHERE k.size_category = 'longboard') AS long_capacity,
                       COUNT(k.id) FILTER (WHERE k.size_category = 'longboard' AND k.status = 'available') AS long_available
                FROM locations l LEFT JOIN lockers k ON k.location_id = l.id
                GROUP BY l.id ORDER BY l.id";
        return $pdo->query($sql)->fetchAll();
    }

    /**
     * Reserve a locker: Available -> Occupied, create subscription + first payment + 4-digit PIN.
     * @throws RuntimeException when fully booked or input invalid
     */
    public static function book(PDO $pdo, int $userId, int $locationId, string $size, int $term, string $cardNumber): array
    {
        if (!in_array($size, self::SIZES, true)) {
            throw new RuntimeException('Invalid board size.');
        }
        if (!isset(self::TERMS[$term])) {
            throw new RuntimeException('Invalid subscription term.');
        }
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $st = $pdo->prepare("SELECT k.id,
                                        CASE k.size_category WHEN 'shortboard' THEN l.shortboard_price_cents
                                                             ELSE l.longboard_price_cents END AS monthly_price_cents
                                 FROM lockers k JOIN locations l ON l.id = k.location_id
                                 WHERE k.location_id = :loc AND k.size_category = :size AND k.status = 'available'
                                 ORDER BY k.id LIMIT 1 FOR UPDATE OF k SKIP LOCKED");
            $st->execute([':loc' => $locationId, ':size' => $size]);
            $locker = $st->fetch();
            if (!$locker) {
                throw new RuntimeException('Fully booked');
            }
            $pdo->prepare("UPDATE lockers SET status = 'occupied' WHERE id = :id")->execute([':id' => $locker['id']]);

            $monthly = (int) $locker['monthly_price_cents'];
            $amount  = self::termPrice($monthly, $term);
            $pin     = self::newPin();
            $digits  = preg_replace('/\D/', '', $cardNumber) ?? '';
            $st = $pdo->prepare("INSERT INTO subscriptions
                                     (user_id, locker_id, status, access_pin, term_months, monthly_price_cents, amount_cents,
                                      renewal_date, auto_renew, card_last4)
                                 VALUES (:u, :k, 'active', :pin, :term, :monthly, :amount,
                                         CURRENT_DATE + :term_interval * INTERVAL '1 month', TRUE, :last4) RETURNING id");
            $st->bindValue(':u', $userId, PDO::PARAM_INT);
            $st->bindValue(':k', (int) $locker['id'], PDO::PARAM_INT);
            $st->bindValue(':pin', $pin);
            $st->bindValue(':term', $term, PDO::PARAM_INT);
            $st->bindValue(':term_interval', $term, PDO::PARAM_INT); // a named parameter may not repeat when emulation is off
            $st->bindValue(':monthly', $monthly, PDO::PARAM_INT);
            $st->bindValue(':amount', $amount, PDO::PARAM_INT);
            $st->bindValue(':last4', substr($digits, -4));
            $st->execute();
            $subId = (int) $st->fetchColumn();

            $pdo->prepare("INSERT INTO payments (subscription_id, amount_cents, status) VALUES (:s, :a, 'paid')")
                ->execute([':s' => $subId, ':a' => $amount]);

            if ($own) {
                $pdo->commit();
            }
            return ['subscription_id' => $subId, 'locker_id' => (int) $locker['id'], 'pin' => $pin, 'amount_cents' => $amount];
        } catch (Throwable $t) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    /** Simulated failed renewal / hard cancel: revoke PIN immediately and free the locker. */
    public static function revoke(PDO $pdo, int $subId, int $userId): bool
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $st = $pdo->prepare("UPDATE subscriptions SET status = 'revoked', access_pin = NULL, auto_renew = FALSE
                             WHERE id = :id AND user_id = :u AND status = 'active' RETURNING locker_id, amount_cents");
        $st->execute([':id' => $subId, ':u' => $userId]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("UPDATE lockers SET status = 'available' WHERE id = :id")->execute([':id' => $row['locker_id']]);
            $pdo->prepare("INSERT INTO payments (subscription_id, amount_cents, status) VALUES (:s, :a, 'failed')")
                ->execute([':s' => $subId, ':a' => $row['amount_cents']]);
        }
        if ($own) {
            $pdo->commit();
        }
        return (bool) $row;
    }

    /** 1-click cancel of auto-renewal; access stays until renewal_date. */
    public static function cancelRenewal(PDO $pdo, int $subId, int $userId): bool
    {
        $st = $pdo->prepare("UPDATE subscriptions SET auto_renew = FALSE WHERE id = :id AND user_id = :u AND status = 'active'");
        $st->execute([':id' => $subId, ':u' => $userId]);
        return $st->rowCount() > 0;
    }

    /** Pay for the next term now: extends the renewal date by one term and switches auto-renewal back on. */
    public static function renew(PDO $pdo, int $subId, int $userId): bool
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $st = $pdo->prepare("UPDATE subscriptions
                             SET renewal_date = renewal_date + term_months * INTERVAL '1 month', auto_renew = TRUE
                             WHERE id = :id AND user_id = :u AND status = 'active' RETURNING amount_cents");
        $st->execute([':id' => $subId, ':u' => $userId]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("INSERT INTO payments (subscription_id, amount_cents, status) VALUES (:s, :a, 'paid')")
                ->execute([':s' => $subId, ':a' => $row['amount_cents']]);
        }
        if ($own) {
            $pdo->commit();
        }
        return (bool) $row;
    }

    /** Issue a fresh 4-digit access code (always different from the current one). Returns null if not active/owned. */
    public static function newCode(PDO $pdo, int $subId, int $userId): ?string
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $st = $pdo->prepare("SELECT access_pin FROM subscriptions WHERE id = :id AND user_id = :u AND status = 'active' FOR UPDATE");
        $st->execute([':id' => $subId, ':u' => $userId]);
        $old = $st->fetchColumn();
        $pin = null;
        if ($old !== false) {
            do {
                $pin = self::newPin();
            } while ($pin === $old);
            $pdo->prepare("UPDATE subscriptions SET access_pin = :pin WHERE id = :id")->execute([':pin' => $pin, ':id' => $subId]);
        }
        if ($own) {
            $pdo->commit();
        }
        return $pin;
    }

    public static function updateCard(PDO $pdo, int $subId, int $userId, string $cardNumber): bool
    {
        $digits = preg_replace('/\D/', '', $cardNumber) ?? '';
        $st = $pdo->prepare("UPDATE subscriptions SET card_last4 = :c WHERE id = :id AND user_id = :u AND status = 'active'");
        $st->execute([':c' => substr($digits, -4), ':id' => $subId, ':u' => $userId]);
        return $st->rowCount() > 0;
    }

    /** @return string[] validation errors (empty = request stored) */
    public static function supportRequest(PDO $pdo, int $userId, ?int $subId, string $topic, string $message): array
    {
        $errors = [];
        $message = trim($message);
        if (!in_array($topic, self::TOPICS, true)) {
            $errors[] = 'Please choose a topic.';
        }
        if ($message === '' || strlen($message) > 2000) {
            $errors[] = 'Please tell us what is going on (max 2000 characters).';
        }
        if ($subId !== null) {
            $st = $pdo->prepare('SELECT 1 FROM subscriptions WHERE id = :id AND user_id = :u');
            $st->execute([':id' => $subId, ':u' => $userId]);
            if (!$st->fetch()) {
                $errors[] = 'That locker does not belong to you.';
            }
        }
        if ($errors === []) {
            $pdo->prepare('INSERT INTO support_requests (user_id, subscription_id, topic, message) VALUES (:u, :s, :t, :m)')
                ->execute([':u' => $userId, ':s' => $subId, ':t' => $topic, ':m' => $message]);
        }
        return $errors;
    }

    /** Process due renewals (auto-renew -> new payment; cancelled -> end & free locker). Called on page loads. */
    public static function processDue(PDO $pdo): void
    {
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $due = $pdo->query("SELECT id, locker_id, auto_renew, amount_cents FROM subscriptions
                            WHERE status = 'active' AND renewal_date <= CURRENT_DATE FOR UPDATE")->fetchAll();
        foreach ($due as $s) {
            if ($s['auto_renew']) {
                $pdo->prepare("UPDATE subscriptions SET renewal_date = renewal_date + term_months * INTERVAL '1 month' WHERE id = :id")
                    ->execute([':id' => $s['id']]);
                $pdo->prepare("INSERT INTO payments (subscription_id, amount_cents, status) VALUES (:s, :a, 'paid')")
                    ->execute([':s' => $s['id'], ':a' => $s['amount_cents']]);
            } else {
                $pdo->prepare("UPDATE subscriptions SET status = 'ended', access_pin = NULL WHERE id = :id")->execute([':id' => $s['id']]);
                $pdo->prepare("UPDATE lockers SET status = 'available' WHERE id = :id")->execute([':id' => $s['locker_id']]);
            }
        }
        if ($own) {
            $pdo->commit();
        }
    }
}
