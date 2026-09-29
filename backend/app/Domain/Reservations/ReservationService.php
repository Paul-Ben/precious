<?php

namespace App\Domain\Reservations;

use App\Domain\Audit\AuditService;
use App\Domain\Guests\GuestService;
use App\Domain\Property\HotelSettings;
use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DocumentSequence;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and changes reservations. Every write runs in a transaction and is
 * audited; room allocation is protected by row locks plus the database's
 * no-overlap exclusion constraint (spec §74).
 */
class ReservationService
{
    /** PostgreSQL SQLSTATE for exclusion-constraint violations. */
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly HotelSettings $settings,
        private readonly GuestService $guests,
        private readonly AuditService $audit,
    ) {}

    /**
     * Validates dates, rooms and occupancy and returns the priced lines.
     *
     * @param  list<array{room_type_id: int, quantity: int}>  $items
     * @return array{dates: StayDates, lines: list<array{room_type: RoomType, quantity: int}>, quote: array<string, mixed>}
     */
    public function prepare(string $checkIn, string $checkOut, array $items, int $adults, int $children): array
    {
        $dates = StayDates::fromStrings($checkIn, $checkOut);
        $settings = $this->settings->all();
        $today = $this->settings->today();

        if ($dates->checkIn->lt($today)) {
            throw new BusinessRuleException('Check-in cannot be in the past.', 'INVALID_DATES');
        }

        if ($dates->checkIn->gt($today->addDays((int) $settings['booking_window_days']))) {
            throw new BusinessRuleException("Reservations can be made up to {$settings['booking_window_days']} days ahead.", 'INVALID_DATES');
        }

        if ($dates->nights() > (int) $settings['max_nights']) {
            throw new BusinessRuleException("A single reservation can be at most {$settings['max_nights']} nights.", 'INVALID_DATES');
        }

        $quantities = collect($items)
            ->groupBy(fn (array $item) => (int) $item['room_type_id'])
            ->map(fn (Collection $group) => (int) $group->sum('quantity'));

        $roomCount = $quantities->sum();

        if ($roomCount < 1 || $roomCount > (int) $settings['max_rooms_per_booking']) {
            throw new BusinessRuleException("Choose between 1 and {$settings['max_rooms_per_booking']} rooms.", 'INVALID_ROOMS');
        }

        $types = RoomType::query()
            ->where('property_id', Property::current()->id)
            ->whereIn('id', $quantities->keys())
            ->active()
            ->get()
            ->keyBy('id');

        if ($types->count() !== $quantities->count()) {
            throw new BusinessRuleException('One or more room types are not available for booking.', 'INVALID_ROOMS');
        }

        $lines = $quantities->map(fn (int $qty, $typeId) => ['room_type' => $types[$typeId], 'quantity' => $qty])->values()->all();

        $maxAdults = collect($lines)->sum(fn ($l) => $l['room_type']->max_adults * $l['quantity']);
        $maxGuests = collect($lines)->sum(fn ($l) => $l['room_type']->max_occupancy * $l['quantity']);

        if ($adults < $roomCount) {
            throw new BusinessRuleException('Each room needs at least one adult.', 'OCCUPANCY_EXCEEDED');
        }

        if ($adults > $maxAdults || $adults + $children > $maxGuests) {
            throw new BusinessRuleException('Too many guests for the selected rooms. Add a room or choose a larger room type.', 'OCCUPANCY_EXCEEDED');
        }

        return ['dates' => $dates, 'lines' => $lines, 'quote' => $this->pricing->quote($lines, $dates)];
    }

    /**
     * @param  array{
     *     check_in: string, check_out: string, adults: int, children?: int,
     *     rooms: list<array{room_type_id: int, quantity: int}>,
     *     guest_id?: string, guest?: array<string, mixed>,
     *     additional_guests?: list<array{first_name: string, last_name: string}>,
     *     special_requests?: ?string, internal_notes?: ?string, hold_minutes?: ?int
     * }  $data
     * @return array{reservation: Reservation, lookup_token: string}
     */
    public function create(array $data, ReservationSource $source, ?User $actor): array
    {
        $children = (int) ($data['children'] ?? 0);
        $prepared = $this->prepare($data['check_in'], $data['check_out'], $data['rooms'], (int) $data['adults'], $children);
        $dates = $prepared['dates'];
        $quote = $prepared['quote'];
        $settings = $this->settings->all();
        $holdMinutes = $source === ReservationSource::Website
            ? (int) $settings['hold_minutes']
            : min((int) ($data['hold_minutes'] ?? $settings['hold_minutes']), (int) $settings['staff_hold_max_minutes']);
        $token = Str::random(40);

        $reservation = DB::transaction(function () use ($data, $source, $actor, $prepared, $dates, $quote, $settings, $holdMinutes, $children, $token) {
            $this->expireStaleHolds();

            $guest = $this->resolveGuest($data, $source, $actor);
            $property = Property::current();

            $reservation = Reservation::create([
                'number' => DocumentSequence::next('RES', (int) $this->settings->today()->year),
                'property_id' => $property->id,
                'guest_id' => $guest->id,
                'booked_by' => $actor?->id,
                'source' => $source,
                'status' => ReservationStatus::PendingPayment,
                'payment_status' => PaymentStatus::Unpaid,
                'check_in' => $dates->checkInDate(),
                'check_out' => $dates->checkOutDate(),
                'nights' => $dates->nights(),
                'adults' => (int) $data['adults'],
                'children' => $children,
                'currency' => $quote['currency'],
                'subtotal' => $quote['subtotal'],
                'service_charge_total' => $quote['service_charge'],
                'tax_total' => $quote['vat'],
                'total' => $quote['total'],
                'deposit_percent' => $quote['deposit_percent'],
                'deposit_amount' => $quote['deposit'],
                'amount_paid' => '0.00',
                'pricing_snapshot' => $quote,
                'free_cancellation_hours' => (int) $settings['free_cancellation_hours'],
                'expires_at' => now()->addMinutes($holdMinutes),
                'special_requests' => $data['special_requests'] ?? null,
                'internal_notes' => $source === ReservationSource::Website ? null : ($data['internal_notes'] ?? null),
                'lookup_token_hash' => hash('sha256', $token),
            ]);

            $this->allocateRooms($reservation, $prepared['lines'], $dates, (int) $data['adults'], $children);

            $reservation->guests()->attach($guest->id, ['is_primary' => true]);

            foreach ($data['additional_guests'] ?? [] as $companion) {
                $companionGuest = $this->guests->create([
                    'first_name' => $companion['first_name'],
                    'last_name' => $companion['last_name'],
                ]);
                $reservation->guests()->attach($companionGuest->id, ['is_primary' => false]);
            }

            $this->audit->record('reservations.created', $reservation, null, [
                'number' => $reservation->number,
                'status' => $reservation->status->value,
                'source' => $source->value,
                'check_in' => $dates->checkInDate(),
                'check_out' => $dates->checkOutDate(),
                'total' => $reservation->total,
                'rooms' => $reservation->rooms()->with('room:id,number')->get()->pluck('room.number')->all(),
            ], ['hold_minutes' => $holdMinutes], $actor);

            return $reservation;
        });

        return ['reservation' => $this->load($reservation), 'lookup_token' => $token];
    }

    /**
     * Picks concrete rooms for each line. Candidate rows are locked; if a
     * concurrent booking wins a room first, the exclusion constraint rejects
     * our insert, we roll back to a savepoint and try the next room.
     *
     * @param  list<array{room_type: RoomType, quantity: int}>  $lines
     */
    private function allocateRooms(Reservation $reservation, array $lines, StayDates $dates, int $adults, int $children): void
    {
        $slots = [];

        foreach ($lines as $line) {
            for ($i = 0; $i < $line['quantity']; $i++) {
                $slots[] = $line['room_type'];
            }
        }

        [$adultsPerRoom, $childrenPerRoom] = $this->distributeGuests($slots, $adults, $children);

        foreach ($slots as $index => $type) {
            $allocated = false;
            $candidates = $this->availability->availableRooms($dates, $type->id)->lockForUpdate()->get();

            foreach ($candidates as $room) {
                if ($reservation->rooms()->where('room_id', $room->id)->exists()) {
                    continue;
                }

                try {
                    DB::transaction(fn () => ReservationRoom::create([
                        'reservation_id' => $reservation->id,
                        'room_id' => $room->id,
                        'room_type_id' => $type->id,
                        'check_in' => $dates->checkInDate(),
                        'check_out' => $dates->checkOutDate(),
                        'adults' => $adultsPerRoom[$index],
                        'children' => $childrenPerRoom[$index],
                        'nightly_rate' => $type->base_rate,
                        'nights' => $dates->nights(),
                        'subtotal' => Money::toDecimal(Money::toMinor($type->base_rate) * $dates->nights()),
                        'is_active' => true,
                    ]));
                    $allocated = true;
                    break;
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
                        continue; // someone else just took this room
                    }

                    throw $e;
                }
            }

            if (! $allocated) {
                throw new BusinessRuleException(
                    "Sorry, there are not enough {$type->name} rooms available for these dates.",
                    'ROOM_UNAVAILABLE',
                    409,
                    ['room_type_id' => $type->id]
                );
            }
        }
    }

    /**
     * Spreads guests over rooms: every room gets one adult first, then the
     * rest fill rooms up to their limits.
     *
     * @param  list<RoomType>  $slots
     * @return array{0: list<int>, 1: list<int>}
     */
    private function distributeGuests(array $slots, int $adults, int $children): array
    {
        $a = array_fill(0, count($slots), 1);
        $c = array_fill(0, count($slots), 0);
        $adults -= count($slots);

        foreach ($slots as $i => $type) {
            $take = min($adults, $type->max_adults - 1);
            $a[$i] += $take;
            $adults -= $take;
        }

        foreach ($slots as $i => $type) {
            $take = min($children, max(0, $type->max_occupancy - $a[$i]));
            $c[$i] += $take;
            $children -= $take;
        }

        return [$a, $c];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveGuest(array $data, ReservationSource $source, ?User $actor): Guest
    {
        if ($source !== ReservationSource::Website && ! empty($data['guest_id'])) {
            return Guest::query()->findOrFail($data['guest_id']);
        }

        if ($source === ReservationSource::Website && $actor?->isCustomer()) {
            return $this->guests->forCustomer($actor, $data['guest'] ?? []);
        }

        return $this->guests->create($data['guest']);
    }

    public function cancel(Reservation $reservation, string $reason, ?User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $reason, $actor) {
            /** @var Reservation $locked */
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isCancellable()) {
                throw new BusinessRuleException(
                    "A reservation that is {$locked->status->value} cannot be cancelled.",
                    'INVALID_STATUS'
                );
            }

            $old = $locked->status;
            $refundEligible = null;

            if (Money::toMinor($locked->amount_paid) > 0) {
                $checkInAt = $this->settings->at(CarbonImmutable::parse($locked->check_in->toDateString()), 'check_in_time');
                $refundEligible = now()->lte($checkInAt->subHours($locked->free_cancellation_hours));
            }

            $locked->forceFill([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'cancellation_reason' => $reason,
                'refund_eligible' => $refundEligible,
                'expires_at' => null,
            ])->save();

            $locked->rooms()->update(['is_active' => false]);

            $this->audit->record('reservations.cancelled', $locked,
                ['status' => $old->value],
                ['status' => ReservationStatus::Cancelled->value],
                ['reason' => $reason, 'refund_eligible' => $refundEligible],
                $actor
            );

            return $this->load($locked);
        });
    }

    /**
     * @param  array{special_requests?: ?string, internal_notes?: ?string}  $data
     */
    public function updateNotes(Reservation $reservation, array $data): Reservation
    {
        return DB::transaction(function () use ($reservation, $data) {
            $before = $reservation->only(array_keys($data));
            $reservation->fill($data)->save();
            [$old, $new] = $this->audit->diff($before, $reservation->only(array_keys($data)));

            if ($new !== []) {
                $this->audit->record('reservations.updated', $reservation, $old, $new);
            }

            return $this->load($reservation);
        });
    }

    /**
     * Unpaid holds past their expiry release their rooms (spec §10 rule 2).
     *
     * @return int number of reservations expired
     */
    public function expireStaleHolds(): int
    {
        return DB::transaction(function () {
            $expired = Reservation::query()
                ->where('status', ReservationStatus::PendingPayment->value)
                ->where('expires_at', '<', now())
                ->lockForUpdate()
                ->get();

            foreach ($expired as $reservation) {
                $reservation->forceFill(['status' => ReservationStatus::Expired])->save();
                $reservation->rooms()->update(['is_active' => false]);
                $this->audit->record('reservations.expired', $reservation, ['status' => 'PENDING_PAYMENT'], ['status' => 'EXPIRED']);
            }

            return $expired->count();
        });
    }

    /**
     * Confirmed reservations whose arrival day has passed without check-in
     * become NO_SHOW and release their rooms (ASSUMPTIONS P7).
     */
    public function markNoShows(CarbonImmutable $arrivalDay): int
    {
        return DB::transaction(function () use ($arrivalDay) {
            $reservations = Reservation::query()
                ->where('status', ReservationStatus::Confirmed->value)
                ->where('check_in', '<=', $arrivalDay->toDateString())
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->forceFill(['status' => ReservationStatus::NoShow])->save();
                $reservation->rooms()->update(['is_active' => false]);
                $this->audit->record('reservations.no_show', $reservation, ['status' => 'CONFIRMED'], ['status' => 'NO_SHOW']);
            }

            return $reservations->count();
        });
    }

    public function findByLookup(string $number, string $token): ?Reservation
    {
        $reservation = Reservation::query()->where('number', $number)->first();

        if (! $reservation || ! $reservation->lookup_token_hash || ! hash_equals($reservation->lookup_token_hash, hash('sha256', $token))) {
            return null;
        }

        return $this->load($reservation);
    }

    public function load(Reservation $reservation): Reservation
    {
        return $reservation->load(['guest', 'guests', 'rooms.room', 'rooms.roomType', 'bookedBy', 'payments.receipt', 'payments.recordedBy', 'payments.refunds', 'stays.room', 'stays.checkedInBy', 'charges.createdBy', 'charges.voidedBy', 'folioStatement']);
    }
}
