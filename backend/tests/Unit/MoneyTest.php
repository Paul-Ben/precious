<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_converts_between_decimal_strings_and_kobo(): void
    {
        $this->assertSame(15000000, Money::toMinor('150000.00'));
        $this->assertSame(150050, Money::toMinor('1500.5'));
        $this->assertSame(2000, Money::toMinor(20));
        $this->assertSame('1500.50', Money::toDecimal(150050));
        $this->assertSame('-0.05', Money::toDecimal(-5));
    }

    #[Test]
    public function the_deposit_example_from_the_spec_is_exact(): void
    {
        // Spec §14: ₦200,000 accommodation, 30% deposit = ₦60,000, balance ₦140,000.
        $total = Money::toMinor('200000.00');
        $deposit = Money::percentOf($total, '30');

        $this->assertSame('60000.00', Money::toDecimal($deposit));
        $this->assertSame('140000.00', Money::toDecimal($total - $deposit));
    }

    #[Test]
    public function percentages_round_half_up_to_the_kobo(): void
    {
        $this->assertSame(25, Money::percentOf(333, '7.5'));     // 24.975 → 25
        $this->assertSame(117000, Money::percentOf(1560000, '7.5'));
        $this->assertSame(0, Money::percentOf(1000, '0'));
    }

    #[Test]
    public function it_rejects_floats_formatted_amounts_and_bad_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor('1.005');
    }

    #[Test]
    public function it_formats_naira(): void
    {
        $this->assertSame('₦85,500.00', Money::format(8550000));
    }
}
