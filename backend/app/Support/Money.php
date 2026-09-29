<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic in minor units (kobo). Amounts enter and leave as
 * decimal strings ("150000.00") exactly as PostgreSQL numeric(14,2) stores
 * them, so no value ever passes through a float (spec §35 rule 2).
 */
final class Money
{
    /** "1500.5" | 1500 → 150050 */
    public static function toMinor(string|int $amount): int
    {
        $value = trim((string) $amount);

        if (! preg_match('/^(-)?(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$value}].");
        }

        $minor = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return ($m[1] ?? '') === '-' ? -$minor : $minor;
    }

    /** 150050 → "1500.50" */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Percentage of an amount, rounded half-up to the kobo.
     * $percent is a decimal string with up to 4 decimal places ("7.5").
     */
    public static function percentOf(int $minor, string|int $percent): int
    {
        $scaled = self::scalePercent($percent); // percent × 10 000
        $product = $minor * $scaled;            // = amount × percent × 10 000
        $divisor = 1_000_000;                   // 100 (percent) × 10 000 (scale)

        $half = intdiv($divisor, 2);

        return $product >= 0
            ? intdiv($product + $half, $divisor)
            : -intdiv(-$product + $half, $divisor);
    }

    /** "₦150,000.00" */
    public static function format(int $minor, string $symbol = '₦'): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.$symbol.number_format(intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function scalePercent(string|int $percent): int
    {
        $value = trim((string) $percent);

        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid percentage [{$value}].");
        }

        return ((int) $m[1]) * 10_000 + (int) str_pad($m[2] ?? '0', 4, '0');
    }
}
