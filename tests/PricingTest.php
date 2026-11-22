<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/LockerService.php';

/** Pure logic - no database needed. Run with:  composer test:unit */
final class PricingTest extends TestCase
{
    public function testMonthlyTermHasNoDiscount(): void
    {
        $this->assertSame(1900, LockerService::termPrice(1900, 1));
    }

    public function testThreeMonthTermIsTenPercentOff(): void
    {
        $this->assertSame(5130, LockerService::termPrice(1900, 3));   // 3 x 19.00 - 10%
    }

    public function testYearlyTermIsTwentyFivePercentOff(): void
    {
        $this->assertSame(26100, LockerService::termPrice(2900, 12)); // 12 x 29.00 - 25%
    }

    public function testValidCardWithSpacesIsAccepted(): void
    {
        $this->assertSame([], LockerService::validateCard('4242 4242 4242 4242', $this->futureExpiry(), '123'));
    }

    public function testCardFailingLuhnCheckIsRejected(): void
    {
        $this->assertContains('Card number is not valid.',
            LockerService::validateCard('4242424242424241', $this->futureExpiry(), '123'));
    }

    public function testExpiredCardIsRejected(): void
    {
        $this->assertContains('Expiry must be a future date in MM/YY format.',
            LockerService::validateCard('4242424242424242', '01/20', '123'));
    }

    public function testBadCvcIsRejected(): void
    {
        $this->assertContains('CVC must be 3 or 4 digits.',
            LockerService::validateCard('4242424242424242', $this->futureExpiry(), '12'));
    }

    public function testSimulatedDeclineCard(): void
    {
        $this->assertContains('Payment declined by the (simulated) card processor.',
            LockerService::validateCard(LockerService::DECLINED_CARD, $this->futureExpiry(), '123'));
    }

    private function futureExpiry(): string
    {
        return date('m') . '/' . date('y', strtotime('+2 years'));
    }
}
