<?php

namespace Tests\Unit;

use App\Domain\Payments\FeeSchedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FeeScheduleTest extends TestCase
{
    private function paystack(): FeeSchedule
    {
        return FeeSchedule::fromArray(['percent' => '1.5', 'flat' => '100.00', 'flat_waived_below' => '2500.00', 'cap' => '2000.00']);
    }

    #[Test]
    public function paystack_fee_follows_the_published_rate(): void
    {
        $fees = $this->paystack();

        $this->assertSame(1_500, $fees->feeFor(100_000));                      // ₦1,000 → ₦15 (₦100 waived under ₦2,500)
        $this->assertSame(45_000 + 10_000, $fees->feeFor(3_000_000));          // ₦30,000 → ₦450 + ₦100
        $this->assertSame(200_000, $fees->feeFor(50_000_000));                 // capped at ₦2,000
    }

    #[Test]
    public function grossing_up_leaves_the_hotel_with_exactly_the_amount_due(): void
    {
        $fees = $this->paystack();

        foreach ([1_000, 200_000, 240_000, 247_000, 4_837_500, 16_125_000, 50_000_000] as $net) {
            $gross = $fees->grossUp($net);

            $this->assertGreaterThanOrEqual($net, $gross - $fees->feeFor($gross), "net {$net}");
            // …and not a kobo more than necessary.
            $this->assertLessThan($net, ($gross - 1) - $fees->feeFor($gross - 1), "net {$net}");
        }

        $this->assertSame(4_921_320, $fees->grossUp(4_837_500));   // ₦48,375 deposit → ₦49,213.20
        $this->assertSame(16_325_000, $fees->grossUp(16_125_000)); // ₦161,250 → cap applies: + ₦2,000
    }

    #[Test]
    public function flutterwave_percentage_only_schedule(): void
    {
        $fees = FeeSchedule::fromArray(['percent' => '2.0', 'flat' => '0.00', 'flat_waived_below' => null, 'cap' => null]);

        $this->assertSame(4_936_225, $fees->grossUp(4_837_500));
        $this->assertSame(0, $fees->grossUp(0));
    }
}
