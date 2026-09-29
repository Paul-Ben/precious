<?php

namespace App\Domain\Reservations;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;

/**
 * A validated [check_in, check_out) date range. check_out is the departure
 * date, so nights = check_out − check_in.
 */
final class StayDates
{
    public function __construct(
        public readonly CarbonImmutable $checkIn,
        public readonly CarbonImmutable $checkOut,
    ) {
        if ($checkOut->lte($checkIn)) {
            throw new BusinessRuleException('Check-out must be after check-in.', 'INVALID_DATES');
        }
    }

    public static function fromStrings(string $checkIn, string $checkOut): self
    {
        return new self(
            CarbonImmutable::createFromFormat('!Y-m-d', $checkIn),
            CarbonImmutable::createFromFormat('!Y-m-d', $checkOut),
        );
    }

    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut, true);
    }

    public function checkInDate(): string
    {
        return $this->checkIn->toDateString();
    }

    public function checkOutDate(): string
    {
        return $this->checkOut->toDateString();
    }
}
