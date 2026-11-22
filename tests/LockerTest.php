<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/LockerService.php';

/**
 * Run against a seeded TEST database (never production):
 *   WS_DB_DSN="pgsql:host=localhost;dbname=wavespace_test" phpunit tests/LockerTest.php
 * Every test runs inside a transaction that is rolled back.
 */
final class LockerTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $locationId;

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO users (email, password_hash, full_name) VALUES ('t" . uniqid() . "@test.dev', 'x', 'Tester')");
        $this->userId = (int) $this->pdo->lastInsertId('users_id_seq');
        $this->locationId = (int) $this->pdo->query("SELECT id FROM locations ORDER BY id LIMIT 1")->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function available(string $size = 'shortboard'): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM lockers WHERE location_id = :l AND size_category = :s AND status = 'available'");
        $st->execute([':l' => $this->locationId, ':s' => $size]);
        return (int) $st->fetchColumn();
    }

    public function testBookingOccupiesLockerAndIssuesFourDigitPin(): void
    {
        $before = $this->available();
        $r = LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242');
        $this->assertSame($before - 1, $this->available());
        $this->assertMatchesRegularExpression('/^\d{4}$/', $r['pin']);
    }

    public function testFullyBookedThrows(): void
    {
        $this->pdo->prepare("UPDATE lockers SET status = 'occupied' WHERE location_id = :l AND size_category = 'shortboard'")
            ->execute([':l' => $this->locationId]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fully booked');
        LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242');
    }

    public function testRevokeDisablesPinAndFreesLocker(): void
    {
        $before = $this->available();
        $r = LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242');
        $this->assertTrue(LockerService::revoke($this->pdo, $r['subscription_id'], $this->userId));
        $this->assertSame($before, $this->available());
        $st = $this->pdo->prepare('SELECT status, access_pin FROM subscriptions WHERE id = :id');
        $st->execute([':id' => $r['subscription_id']]);
        $row = $st->fetch();
        $this->assertSame('revoked', $row['status']);
        $this->assertNull($row['access_pin']);
    }

    public function testCancelRenewalKeepsAccessActive(): void
    {
        $r = LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242');
        $this->assertTrue(LockerService::cancelRenewal($this->pdo, $r['subscription_id'], $this->userId));
        $st = $this->pdo->prepare('SELECT status, auto_renew, access_pin FROM subscriptions WHERE id = :id');
        $st->execute([':id' => $r['subscription_id']]);
        $row = $st->fetch();
        $this->assertSame('active', $row['status']);
        $this->assertFalse($row['auto_renew']);
        $this->assertNotNull($row['access_pin']);
    }

    public function testExpiredCancelledSubscriptionFreesLocker(): void
    {
        $before = $this->available();
        $r = LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242');
        LockerService::cancelRenewal($this->pdo, $r['subscription_id'], $this->userId);
        $this->pdo->exec("UPDATE subscriptions SET renewal_date = CURRENT_DATE - 1 WHERE id = " . (int) $r['subscription_id']);
        LockerService::processDue($this->pdo);
        $this->assertSame($before, $this->available());
    }

    public function testOtherUserCannotRevoke(): void
    {
        $r = LockerService::book($this->pdo, $this->userId, $this->locationId, 'shortboard', 1, '4242424242424242');
        $this->assertFalse(LockerService::revoke($this->pdo, $r['subscription_id'], $this->userId + 9999));
    }

    public function testCardValidation(): void
    {
        $next = date('m') . '/' . date('y', strtotime('+2 years'));
        $this->assertSame([], LockerService::validateCard('4242 4242 4242 4242', $next, '123'));
        $this->assertNotEmpty(LockerService::validateCard('4242424242424241', $next, '123'));
        $this->assertNotEmpty(LockerService::validateCard('4242424242424242', '01/20', '123'));
        $this->assertNotEmpty(LockerService::validateCard(LockerService::DECLINED_CARD, $next, '123'));
    }
}
