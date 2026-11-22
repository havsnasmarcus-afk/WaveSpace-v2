<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/LockerService.php';
require_once __DIR__ . '/../src/StaffService.php';

/** Staff back office. Same setup as LockerTest: a seeded TEST database, every test rolled back. */
final class StaffTest extends TestCase
{
    private PDO $pdo;
    private array $admin = ['id' => 0, 'role' => 'admin'];
    private array $staff = ['id' => 0, 'role' => 'staff'];

    protected function setUp(): void
    {
        $this->pdo = db();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function input(array $over = []): array
    {
        return $over + ['full_name' => 'Rita Sousa', 'email' => 'rita' . uniqid() . '@wavespace.pt',
                        'nif' => '501964843', 'phone' => '+351 912 345 678', 'role' => 'staff', 'password' => 'longenough'];
    }

    public function testNifValidation(): void
    {
        $this->assertTrue(StaffService::validNif('123456789'));
        $this->assertTrue(StaffService::validNif('501964843'));
        $this->assertFalse(StaffService::validNif('123456780'));   // wrong check digit
        $this->assertFalse(StaffService::validNif('12345678'));    // too short
        $this->assertFalse(StaffService::validNif('412345678'));   // invalid first digit
    }

    public function testCreateStaffAndRejectDuplicateNif(): void
    {
        $r = StaffService::createStaff($this->pdo, $this->admin, $this->input());
        $this->assertSame([], $r['errors']);
        $dup = StaffService::createStaff($this->pdo, $this->admin, $this->input());
        $this->assertContains('Another account already uses this NIF.', $dup['errors']);
    }

    public function testOnlyAdminCanGrantAdmin(): void
    {
        $r = StaffService::createStaff($this->pdo, $this->staff, $this->input(['role' => 'admin']));
        $this->assertContains('Only an admin can give someone the admin role.', $r['errors']);
    }

    public function testCannotDeactivateYourself(): void
    {
        $id = StaffService::createStaff($this->pdo, $this->admin, $this->input())['id'];
        $me = ['id' => $id, 'role' => 'staff'];
        $errors = StaffService::updateStaff($this->pdo, $me, $id, $this->input(['active' => false, 'password' => '']));
        $this->assertContains('You cannot deactivate your own account.', $errors);
    }

    public function testCreateBeachWithLockersAndAddMore(): void
    {
        $r = StaffService::createLocation($this->pdo, ['name' => 'Nazaré', 'region' => 'Leiria', 'latitude' => 39.6, 'longitude' => -9.07,
            'shortboard_price' => 19, 'longboard_price' => 29, 'shortboard_count' => 3, 'longboard_count' => 2]);
        $this->assertSame([], $r['errors']);
        $this->assertSame(2, StaffService::addLockers($this->pdo, $r['id'], 'longboard', 2));
        $labels = $this->pdo->query('SELECT label FROM lockers WHERE location_id = ' . $r['id'] . ' ORDER BY label::int')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7'], $labels);
    }

    public function testRentedLockerCannotBeDeleted(): void
    {
        $occupied = (int) $this->pdo->query("SELECT id FROM lockers WHERE status = 'occupied' LIMIT 1")->fetchColumn();
        $this->assertFalse(StaffService::deleteLocker($this->pdo, $occupied));
    }
}
