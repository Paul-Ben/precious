<?php

namespace App\Domain\Reservations;

use App\Domain\Property\HotelSettings;
use App\Models\RoomType;
use App\Support\Money;

/**
 * The one place accommodation prices are calculated (spec §75). The result is
 * stored on the reservation as its price snapshot, so later rate or tax
 * changes never alter an existing booking (spec §10 rule 9).
 */
class PricingService
{
    public function __construct(private readonly HotelSettings $settings) {}

    /**
     * @param  list<array{room_type: RoomType, quantity: int}>  $lines
     * @return array{
     *     nights: int,
     *     lines: list<array{room_type_id: int, room_type: string, quantity: int, nightly_rate: string, nights: int, subtotal: string}>,
     *     subtotal: string, service_charge_percent: string, service_charge: string,
     *     vat_percent: string, vat: string, total: string, deposit_percent: string, deposit: string,
     *     balance_after_deposit: string, currency: string
     * }
     */
    public function quote(array $lines, StayDates $dates): array
    {
        $settings = $this->settings->all();
        $nights = $dates->nights();
        $subtotal = 0;
        $out = [];

        foreach ($lines as $line) {
            $rate = Money::toMinor($line['room_type']->base_rate);
            $lineTotal = $rate * $nights * $line['quantity'];
            $subtotal += $lineTotal;

            $out[] = [
                'room_type_id' => $line['room_type']->id,
                'room_type' => $line['room_type']->name,
                'quantity' => $line['quantity'],
                'nightly_rate' => Money::toDecimal($rate),
                'nights' => $nights,
                'subtotal' => Money::toDecimal($lineTotal),
            ];
        }

        $scPercent = (string) $settings['accommodation_service_charge_percent'];
        $serviceCharge = Money::percentOf($subtotal, $scPercent);

        $vatPercent = $settings['vat_on_accommodation'] ? (string) $settings['vat_percent'] : '0';
        $vat = Money::percentOf($subtotal + $serviceCharge, $vatPercent);

        $total = $subtotal + $serviceCharge + $vat;
        $depositPercent = (string) $settings['deposit_percent'];
        $deposit = Money::percentOf($total, $depositPercent);

        return [
            'nights' => $nights,
            'lines' => $out,
            'subtotal' => Money::toDecimal($subtotal),
            'service_charge_percent' => $scPercent,
            'service_charge' => Money::toDecimal($serviceCharge),
            'vat_percent' => $vatPercent,
            'vat' => Money::toDecimal($vat),
            'total' => Money::toDecimal($total),
            'deposit_percent' => $depositPercent,
            'deposit' => Money::toDecimal($deposit),
            'balance_after_deposit' => Money::toDecimal($total - $deposit),
            'currency' => config('hotel.currency'),
        ];
    }
}
