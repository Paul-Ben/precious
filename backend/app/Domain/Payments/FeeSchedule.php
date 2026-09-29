<?php

namespace App\Domain\Payments;

use App\Models\PaymentGatewaySetting;
use App\Support\Money;
use InvalidArgumentException;

/**
 * A gateway's transaction fee: percent + flat (optionally waived below a
 * threshold), optionally capped. Works in kobo; percentages round UP so the
 * hotel never ends up short when the fee is passed on to the payer.
 */
final class FeeSchedule
{
    public function __construct(
        public readonly string $percent,
        public readonly int $flatMinor = 0,
        public readonly ?int $flatWaivedBelowMinor = null,
        public readonly ?int $capMinor = null,
    ) {
        if (! preg_match('/^\d{1,2}(\.\d{1,4})?$/', $percent)) {
            throw new InvalidArgumentException("Invalid fee percentage [{$percent}].");
        }
    }

    public static function forSetting(PaymentGatewaySetting $setting): self
    {
        return self::fromArray(self::valuesFor($setting));
    }

    /**
     * Effective fee values for a gateway (saved override or published default).
     *
     * @return array{percent: string, flat: string, flat_waived_below: ?string, cap: ?string}
     */
    public static function valuesFor(PaymentGatewaySetting $setting): array
    {
        $defaults = config('payments.default_fees.'.$setting->gateway) ?? ['percent' => '0', 'flat' => '0.00', 'flat_waived_below' => null, 'cap' => null];

        return array_merge($defaults, array_intersect_key($setting->options['fees'] ?? [], $defaults));
    }

    /**
     * @param  array{percent: string|int, flat?: ?string, flat_waived_below?: ?string, cap?: ?string}  $values
     */
    public static function fromArray(array $values): self
    {
        $minor = fn ($v) => $v === null || $v === '' ? null : Money::toMinor((string) $v);

        return new self(
            (string) $values['percent'],
            $minor($values['flat'] ?? null) ?? 0,
            $minor($values['flat_waived_below'] ?? null),
            $minor($values['cap'] ?? null),
        );
    }

    /** Fee the gateway deducts from a charge of $chargedMinor. */
    public function feeFor(int $chargedMinor): int
    {
        [$whole, $fraction] = array_pad(explode('.', $this->percent), 2, '');
        $scaled = ((int) $whole) * 10_000 + (int) str_pad($fraction, 4, '0'); // percent × 10 000
        $percentFee = intdiv($chargedMinor * $scaled + 999_999, 1_000_000);   // ceil

        $flat = ($this->flatWaivedBelowMinor !== null && $chargedMinor < $this->flatWaivedBelowMinor) ? 0 : $this->flatMinor;
        $fee = $percentFee + $flat;

        return $this->capMinor !== null ? min($fee, $this->capMinor) : $fee;
    }

    /**
     * Smallest charge whose net (after the gateway fee) covers $netMinor.
     * The difference is the fee passed on to the payer.
     */
    public function grossUp(int $netMinor): int
    {
        if ($netMinor <= 0) {
            return 0;
        }

        $gross = $netMinor;

        // Fee grows more slowly than the charge, so this converges in a few steps.
        for ($i = 0; $i < 20; $i++) {
            $next = $netMinor + $this->feeFor($gross);

            if ($next === $gross) {
                break;
            }

            $gross = $next;
        }

        while ($gross - $this->feeFor($gross) < $netMinor) {
            $gross++;
        }

        return $gross;
    }

    /**
     * @return array{percent: string, flat: string, flat_waived_below: ?string, cap: ?string}
     */
    public function toArray(): array
    {
        return [
            'percent' => $this->percent,
            'flat' => Money::toDecimal($this->flatMinor),
            'flat_waived_below' => $this->flatWaivedBelowMinor === null ? null : Money::toDecimal($this->flatWaivedBelowMinor),
            'cap' => $this->capMinor === null ? null : Money::toDecimal($this->capMinor),
        ];
    }
}
