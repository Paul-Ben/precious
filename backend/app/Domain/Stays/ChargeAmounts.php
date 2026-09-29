<?php

namespace App\Domain\Stays;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Line maths for a bill item, in kobo: subtotal = unit × quantity,
 * service charge on the subtotal, VAT on subtotal + service charge
 * (same order as PricingService for accommodation).
 */
final class ChargeAmounts
{
    public function __construct(
        public readonly int $unit,
        public readonly string $quantity,
        public readonly int $subtotal,
        public readonly int $serviceCharge,
        public readonly int $vat,
    ) {}

    public static function compute(int $unitMinor, string $quantity, string $serviceChargePercent, string $vatPercent): self
    {
        if (! preg_match('/^(\d{1,4})(?:\.(\d{1,2}))?$/', $quantity, $m) || (int) $m[1] + (int) ($m[2] ?? 0) === 0) {
            throw new InvalidArgumentException("Invalid quantity [{$quantity}].");
        }

        $hundredths = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        $product = $unitMinor * $hundredths;
        // Half-up to the kobo, symmetric for negative adjustments.
        $subtotal = $product >= 0 ? intdiv($product + 50, 100) : -intdiv(-$product + 50, 100);

        $serviceCharge = Money::percentOf($subtotal, $serviceChargePercent);
        $vat = Money::percentOf($subtotal + $serviceCharge, $vatPercent);

        return new self($unitMinor, Money::toDecimal($hundredths), $subtotal, $serviceCharge, $vat);
    }

    public function total(): int
    {
        return $this->subtotal + $this->serviceCharge + $this->vat;
    }
}
