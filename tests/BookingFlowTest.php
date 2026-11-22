<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/LockerService.php';

/**
 * Critical user flows end to end at the service layer (the same calls the API makes),
 * against the scratch test database. Every test is rolled back.
 */
final class BookingFlowTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $st = $this->pdo->prepare('INSERT INTO users (email, password_hash, full_name) VALUES (:e, :h, :n) RETURNING id');
        $st->execute([':e' => 'flow' . uniqid() . '@test.dev', ':h' => password_hash('surfing123', PASSWORD_DEFAULT), ':n' => 'Flow Tester']);
        $this->userId = (int) $st->fetchColumn();
        // a beach that has at least one free shortboard locker
        $this->locationId = (int) $this->pdo->query("SELECT location_id FROM lockers
            WHERE size_category = 'shortboard' AND status = 'available' ORDER BY location_id LIMIT 1")->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function beach(): array
    {
        foreach (LockerService::availability($this->pdo) as $row) {
            if ((int) $row['id'] === $this->locationId) {
                return $row;
            }
        }
        $this->fail('Beach not found in availability list');
    }

    private function subscription(int $id): array
    {
        $st = $this->pdo->prepare('SELECT s.*, k.status AS locker_status FROM subscriptions s JOIN lockers k ON k.id = s.locker_id WHERE s.id = :id');
        $st->execute([':id' => $id]);
        return $st->fetch();
    }

    /** Story 3.1 -> 3.2 -> 3.3: map shows a free locker, user books it, gets an active PIN and a paid payment. */
    public function testBrowseBookAndReceivePin(): void
    {
        $before = $this->beach();
        $r = LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 3, '4242424242424242');
        $after = $this->beach();

        $this->assertSame((int) $before['short_available'] - 1, (int) $after['short_available'], 'map count drops by one');
        $sub = $this->subscription((int) $r['subscription_id']);
        $this->assertSame('active', $sub['status']);
        $this->assertSame('occupied', $sub['locker_status']);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $sub['access_pin']);
        $this->assertSame('4242', $sub['card_last4']);
        $this->assertSame(LockerService::termPrice((int) $sub['monthly_price_cents'], 3), (int) $sub['amount_cents']);

        $pay = $this->pdo->prepare("SELECT amount_cents, status FROM payments WHERE subscription_id = :s");
        $pay->execute([':s' => $r['subscription_id']]);
        $this->assertSame([['amount_cents' => (int) $sub['amount_cents'], 'status' => 'paid']],
            array_map(fn($p) => ['amount_cents' => (int) $p['amount_cents'], 'status' => $p['status']], $pay->fetchAll()));
    }

    /** Story 3.4: cancel renewal keeps access until the paid period ends, then the locker is released. */
    public function testCancelRenewalThenPeriodEnds(): void
    {
        $id = (int) LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242')['subscription_id'];
        LockerService::cancelRenewal($this->pdo, $id, $this->userId);
        $this->assertNotNull($this->subscription($id)['access_pin'], 'still has access before the renewal date');

        $this->pdo->exec('UPDATE subscriptions SET renewal_date = CURRENT_DATE WHERE id = ' . $id);
        LockerService::processDue($this->pdo);

        $sub = $this->subscription($id);
        $this->assertSame('ended', $sub['status']);
        $this->assertNull($sub['access_pin']);
        $this->assertSame('available', $sub['locker_status']);
    }

    /** Auto-renew: a due subscription is charged again and its renewal date moves forward by one term. */
    public function testAutoRenewChargesAndExtends(): void
    {
        $id = (int) LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242')['subscription_id'];
        $this->pdo->exec('UPDATE subscriptions SET renewal_date = CURRENT_DATE WHERE id = ' . $id);
        LockerService::processDue($this->pdo);

        $sub = $this->subscription($id);
        $this->assertSame('active', $sub['status']);
        $this->assertGreaterThan(date('Y-m-d'), $sub['renewal_date']);
        $n = $this->pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'paid' AND subscription_id = " . $id)->fetchColumn();
        $this->assertSame(2, (int) $n);
    }

    /** Database guard: one locker can never have two active subscriptions, even if app logic were bypassed. */
    public function testDatabaseRejectsSecondActiveSubscriptionOnSameLocker(): void
    {
        $id = (int) LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242')['subscription_id'];
        $lockerId = (int) $this->subscription($id)['locker_id'];

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO subscriptions (user_id, locker_id, status, access_pin, monthly_price_cents, amount_cents, renewal_date, card_last4)
                          VALUES ({$this->userId}, {$lockerId}, 'active', '1234', 1900, 1900, CURRENT_DATE + 30, '4242')");
    }
}
