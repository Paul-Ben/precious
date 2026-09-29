<?php

namespace App\Domain\Stays;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\AvailabilityService;
use App\Domain\Reservations\StayDates;
use App\Enums\ChargeCategory;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\FolioStatement;
use App\Models\Reservation;
use App\Models\ReservationCharge;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Check-in, stay changes and check-out (spec §18-19, ASSUMPTIONS P3/P4/P8/P15-P18).
 */
class StayService
{
    private const EXCLUSION_VIOLATION = '23P01';

    /** Room statuses in which a guest can be given the key. */
    private const READY = [RoomStatus::Available, RoomStatus::Reserved];

    public function __construct(
        private readonly HotelSettings $settings,
        private readonly AvailabilityService $availability,
        private readonly FolioService $folio,
        private readonly AuditService $audit,
    ) {}

    // ------------------------------------------------------------------ Check-in

    /**
     * What the desk needs to check a booking in: blockers and room choices.
     *
     * @return array<string, mixed>
     */
    public function checkInOptions(Reservation $reservation): array
    {
        $reservation->loadMissing(['rooms.room', 'rooms.roomType', 'guest.documents']);
        $today = $this->settings->today()->toDateString();
        [$in, $out] = [$reservation->check_in->toDateString(), $reservation->check_out->toDateString()];

        $blockers = [];

        if ($reservation->status === ReservationStatus::PendingPayment) {
            $blockers[] = 'The deposit of '.Money::format(Money::toMinor($reservation->deposit_amount)).' must be paid first.';
        } elseif ($reservation->status !== ReservationStatus::Confirmed) {
            $blockers[] = 'Only confirmed reservations can be checked in.';
        }

        if ($today < $in || $today >= $out) {
            $blockers[] = 'Check-in is possible from '.$reservation->check_in->format('j M').' until the day before departure.';
        }

        $lines = $reservation->rooms->where('is_active', true)->values();
        $from = max($today, $in);
        $dates = $from < $out ? StayDates::fromStrings($from, $out) : null;

        return [
            'can_check_in' => $blockers === [],
            'blockers' => $blockers,
            'id_required' => $this->idRequired($reservation),
            'id_on_file' => $reservation->guest?->documents->isNotEmpty() ?? false,
            'rooms' => $lines->map(function (ReservationRoom $line) use ($dates) {
                $candidates = $dates
                    ? $this->availability->availableRooms($dates)->with('roomType:id,name')->get()
                    : collect();

                return [
                    'reservation_room_id' => $line->id,
                    'room_type' => ['id' => $line->room_type_id, 'name' => $line->roomType?->name],
                    'adults' => $line->adults,
                    'children' => $line->children,
                    'current' => $line->room ? [
                        'id' => $line->room->id,
                        'number' => $line->room->number,
                        'status' => $line->room->status->value,
                        'ready' => in_array($line->room->status, self::READY, true),
                    ] : null,
                    // Free for the whole stay and clean. Same type first; others are up/downgrades.
                    'alternatives' => $candidates
                        ->filter(fn (Room $r) => in_array($r->status, self::READY, true))
                        ->sortBy(fn (Room $r) => [$r->room_type_id === $line->room_type_id ? 0 : 1, strlen($r->number), $r->number])
                        ->map(fn (Room $r) => [
                            'id' => $r->id,
                            'number' => $r->number,
                            'room_type' => $r->roomType?->name,
                            'same_type' => $r->room_type_id === $line->room_type_id,
                        ])->values()->all(),
                ];
            })->all(),
        ];
    }

    /**
     * @param  array{rooms?: list<array{reservation_room_id: int, room_id: int}>, id_type?: ?string, id_number?: ?string, notes?: ?string}  $data
     */
    public function checkIn(Reservation $reservation, array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            $locked = $this->lock($reservation);
            $locked->load(['rooms', 'guest.documents']);
            $today = $this->settings->today()->toDateString();

            if ($locked->status === ReservationStatus::PendingPayment) {
                throw new BusinessRuleException(
                    'The deposit of '.Money::format(Money::toMinor($locked->deposit_amount)).' must be paid before check-in.',
                    'DEPOSIT_REQUIRED',
                    409
                );
            }

            if ($locked->status !== ReservationStatus::Confirmed) {
                throw new BusinessRuleException("A {$locked->status->value} reservation cannot be checked in.", 'INVALID_STATUS', 409);
            }

            if ($today < $locked->check_in->toDateString() || $today >= $locked->check_out->toDateString()) {
                throw new BusinessRuleException(
                    'This reservation arrives on '.$locked->check_in->format('j M Y').'. Change the dates to check in on another day.',
                    'NOT_ARRIVAL_DAY',
                    422
                );
            }

            $idType = $data['id_type'] ?? null;
            $idNumber = $data['id_number'] ?? null;

            if ($this->idRequired($locked) && $locked->guest?->documents->isEmpty() && (! $idType || ! $idNumber)) {
                throw new BusinessRuleException('Record the guest\'s ID (type and number) or upload it to their profile first.', 'ID_REQUIRED', 422);
            }

            $choices = collect($data['rooms'] ?? [])->pluck('room_id', 'reservation_room_id');
            $stays = [];

            foreach ($locked->rooms->where('is_active', true) as $line) {
                /** @var ReservationRoom $line */
                $roomId = (int) ($choices[$line->id] ?? $line->room_id);

                if ($roomId !== $line->room_id) {
                    $this->reassign($line, $roomId);
                }

                /** @var Room $room */
                $room = Room::query()->whereKey($line->room_id)->lockForUpdate()->firstOrFail();

                if (! in_array($room->status, self::READY, true)) {
                    throw new BusinessRuleException(
                        "Room {$room->number} is not ready ({$room->status->value}). Choose another room or mark it clean.",
                        'ROOM_NOT_READY',
                        409,
                        ['room' => $room->number]
                    );
                }

                $stays[] = Stay::create([
                    'property_id' => $locked->property_id,
                    'reservation_id' => $locked->id,
                    'reservation_room_id' => $line->id,
                    'room_id' => $room->id,
                    'guest_id' => $locked->guest_id,
                    'status' => StayStatus::Open,
                    'checked_in_at' => now(),
                    'checked_in_by' => $actor->id,
                    'id_type' => $idType,
                    'id_number' => $idNumber,
                    'notes' => $data['notes'] ?? null,
                ]);

                $room->forceFill(['status' => RoomStatus::Occupied])->save();
            }

            $locked->forceFill(['status' => ReservationStatus::CheckedIn, 'checked_in_at' => now()])->save();

            $this->audit->record('reservations.checked_in', $locked,
                ['status' => 'CONFIRMED'],
                ['status' => 'CHECKED_IN'],
                [
                    'rooms' => collect($stays)->map(fn (Stay $s) => $s->room_id)->all(),
                    'id_recorded_at_desk' => (bool) $idType,
                ],
                $actor
            );

            return $locked;
        });
    }

    /** Moves a booked line to another free room for its whole date range. */
    private function reassign(ReservationRoom $line, int $roomId): void
    {
        $dates = StayDates::fromStrings($line->check_in->toDateString(), $line->check_out->toDateString());
        $room = Room::query()->whereKey($roomId)->first();

        if (! $room || ! $room->is_active || $room->property_id !== $line->reservation->property_id) {
            throw new BusinessRuleException('That room does not exist.', 'INVALID_ROOM', 422);
        }

        $free = $this->availability->availableRooms($dates)->whereKey($roomId)->exists();

        if (! $free) {
            throw new BusinessRuleException("Room {$room->number} is not free for these dates.", 'ROOM_UNAVAILABLE', 409, ['room' => $room->number]);
        }

        try {
            DB::transaction(fn () => $line->forceFill(['room_id' => $roomId])->save());
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
                throw new BusinessRuleException("Room {$room->number} was just taken. Choose another room.", 'ROOM_UNAVAILABLE', 409);
            }

            throw $e;
        }
    }

    private function idRequired(Reservation $reservation): bool
    {
        return (bool) $this->settings->get('require_id_at_check_in');
    }

    // ------------------------------------------------------------ During the stay

    /**
     * Adds nights at the booked nightly rate (posted as EXTRA_NIGHT charges).
     */
    public function extend(Reservation $reservation, string $newCheckOut, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $newCheckOut, $actor) {
            $locked = $this->lock($reservation);

            if (! in_array($locked->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
                throw new BusinessRuleException('Only confirmed or checked-in reservations can be extended.', 'INVALID_STATUS', 409);
            }

            $oldOut = CarbonImmutable::parse($locked->check_out->toDateString());
            $newOut = CarbonImmutable::parse($newCheckOut);
            $added = (int) $oldOut->diffInDays($newOut, false);

            if ($added < 1) {
                throw new BusinessRuleException('The new departure date must be after '.$oldOut->format('j M Y').'.', 'INVALID_DATES', 422);
            }

            if ($locked->nights + $added > (int) $this->settings->get('max_nights')) {
                throw new BusinessRuleException('A reservation can be at most '.$this->settings->get('max_nights').' nights.', 'INVALID_DATES', 422);
            }

            $settings = $this->settings->all();
            $scPercent = (string) $settings['accommodation_service_charge_percent'];
            $vatPercent = $settings['vat_on_accommodation'] ? (string) $settings['vat_percent'] : '0';

            $lines = ReservationRoom::query()
                ->where('reservation_id', $locked->id)
                ->where('is_active', true)
                ->whereDate('check_out', $oldOut->toDateString())
                ->with('room')
                ->get();

            // The added nights must be free of other bookings and of room blocks
            // (the exclusion constraint only covers reservation_rooms).
            $extra = StayDates::fromStrings($oldOut->toDateString(), $newOut->toDateString());

            foreach ($lines as $line) {
                if (! $this->availability->availableRooms($extra)->whereKey($line->room_id)->exists()) {
                    throw new BusinessRuleException(
                        "Room {$line->room?->number} is not free in that period. Move the guest to a free room first.",
                        'ROOM_UNAVAILABLE',
                        409,
                        ['room' => $line->room?->number]
                    );
                }

                try {
                    DB::transaction(fn () => $line->forceFill([
                        'check_out' => $newOut->toDateString(),
                        'nights' => $line->nights + $added,
                    ])->save());
                } catch (QueryException $e) {
                    if (($e->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
                        throw new BusinessRuleException(
                            "Room {$line->room?->number} is booked by another guest in that period. Move the guest to a free room first.",
                            'ROOM_UNAVAILABLE',
                            409,
                            ['room' => $line->room?->number]
                        );
                    }

                    throw $e;
                }

                $stay = Stay::query()->where('reservation_room_id', $line->id)->where('status', StayStatus::Open->value)->first();

                $this->folio->post(
                    $locked,
                    ChargeCategory::ExtraNight,
                    'Extra night'.($added > 1 ? 's' : '')." – Room {$line->room?->number} ({$oldOut->format('j M')} → {$newOut->format('j M')})",
                    ChargeAmounts::compute(Money::toMinor($line->nightly_rate), (string) $added, $scPercent, $vatPercent),
                    $stay,
                    $actor,
                );
            }

            $locked->forceFill(['check_out' => $newOut->toDateString(), 'nights' => $locked->nights + $added])->save();

            $this->audit->record('reservations.extended', $locked,
                ['check_out' => $oldOut->toDateString()],
                ['check_out' => $newOut->toDateString()],
                ['nights_added' => $added],
                $actor
            );

            return $locked;
        });
    }

    /**
     * Moves an in-house guest to another room from today. The old stay closes
     * (MOVED), its room goes to DIRTY, and a new stay opens.
     */
    public function move(Stay $stay, int $roomId, string $reason, User $actor): Stay
    {
        return DB::transaction(function () use ($stay, $roomId, $reason, $actor) {
            $reservation = $this->lock($stay->reservation);
            /** @var Stay $locked */
            $locked = Stay::query()->whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $line = ReservationRoom::query()->whereKey($locked->reservation_room_id)->lockForUpdate()->firstOrFail();
            $today = $this->settings->today()->toDateString();
            [$lineIn, $lineOut] = [$line->check_in->toDateString(), $line->check_out->toDateString()];

            if ($locked->status !== StayStatus::Open || $reservation->status !== ReservationStatus::CheckedIn) {
                throw new BusinessRuleException('Only an in-house guest can be moved.', 'INVALID_STATUS', 409);
            }

            if ($roomId === $locked->room_id) {
                throw new BusinessRuleException('The guest is already in that room.', 'INVALID_ROOM', 422);
            }

            if ($today >= $lineOut) {
                throw new BusinessRuleException('The guest leaves today. Check them out instead.', 'INVALID_DATES', 422);
            }

            $newRoom = Room::query()->whereKey($roomId)->lockForUpdate()->first();

            if (! $newRoom || ! $newRoom->is_active || $newRoom->property_id !== $reservation->property_id) {
                throw new BusinessRuleException('That room does not exist.', 'INVALID_ROOM', 422);
            }

            if (! in_array($newRoom->status, self::READY, true)) {
                throw new BusinessRuleException("Room {$newRoom->number} is not ready ({$newRoom->status->value}).", 'ROOM_NOT_READY', 409);
            }

            $from = max($today, $lineIn);
            $dates = StayDates::fromStrings($from, $lineOut);

            if (! $this->availability->availableRooms($dates)->whereKey($roomId)->exists()) {
                throw new BusinessRuleException("Room {$newRoom->number} is not free until {$line->check_out->format('j M')}.", 'ROOM_UNAVAILABLE', 409);
            }

            $oldRoom = Room::query()->whereKey($locked->room_id)->lockForUpdate()->firstOrFail();

            try {
                $newLine = DB::transaction(function () use ($line, $from, $lineIn, $roomId, $dates) {
                    if ($from === $lineIn) {
                        // Moving on arrival day: simply swap the room.
                        $line->forceFill(['room_id' => $roomId])->save();

                        return $line;
                    }

                    $rate = Money::toMinor($line->nightly_rate);
                    $usedNights = (int) CarbonImmutable::parse($lineIn)->diffInDays(CarbonImmutable::parse($from));
                    $restNights = $dates->nights();
                    $original = $line->replicate();

                    $line->forceFill([
                        'check_out' => $from,
                        'nights' => $usedNights,
                        'subtotal' => Money::toDecimal($rate * $usedNights),
                    ])->save();

                    return ReservationRoom::create([
                        ...$original->only(['reservation_id', 'room_type_id', 'adults', 'children', 'nightly_rate', 'is_active']),
                        'room_id' => $roomId,
                        'check_in' => $from,
                        'check_out' => $dates->checkOutDate(),
                        'nights' => $restNights,
                        'subtotal' => Money::toDecimal($rate * $restNights),
                    ]);
                });
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
                    throw new BusinessRuleException("Room {$newRoom->number} was just taken. Choose another room.", 'ROOM_UNAVAILABLE', 409);
                }

                throw $e;
            }

            $locked->forceFill([
                'status' => StayStatus::Closed,
                'close_reason' => 'MOVED',
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $newStay = Stay::create([
                ...$locked->only(['property_id', 'reservation_id', 'guest_id', 'id_type', 'id_number', 'notes']),
                'reservation_room_id' => $newLine->id,
                'room_id' => $roomId,
                'status' => StayStatus::Open,
                'checked_in_at' => now(),
                'checked_in_by' => $actor->id,
            ]);

            $oldRoom->forceFill(['status' => RoomStatus::Dirty])->save();
            $newRoom->forceFill(['status' => RoomStatus::Occupied])->save();

            $this->audit->record('stays.moved', $newStay,
                ['room' => $oldRoom->number],
                ['room' => $newRoom->number],
                ['reservation' => $reservation->number, 'reason' => $reason],
                $actor
            );

            return $newStay;
        });
    }

    // ----------------------------------------------------------------- Check-out

    /**
     * Late check-out fee for leaving now (P4): after check_out_time on the
     * departure day, half the nightly rate until the half-rate cut-off, then
     * a full night.
     *
     * @return array{percent: int, lines: list<array{stay_id: string, room_number: ?string, nightly_rate: string, amounts: ChargeAmounts}>, total: int}
     */
    public function lateFee(Reservation $reservation, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now($this->settings->today()->timezone);
        $departure = CarbonImmutable::parse($reservation->check_out->toDateString(), $at->timezone);
        $today = $at->startOfDay();

        $percent = 0;

        if ($today->equalTo($departure)) {
            $checkOutAt = $this->settings->at($departure, 'check_out_time');
            $halfUntil = $this->settings->at($departure, 'late_checkout_half_rate_until');

            $percent = match (true) {
                $at->lte($checkOutAt) => 0,
                $at->lte($halfUntil) => 50,
                default => 100,
            };
        }

        if ($percent === 0) {
            return ['percent' => 0, 'lines' => [], 'total' => 0];
        }

        $settings = $this->settings->all();
        $scPercent = (string) $settings['accommodation_service_charge_percent'];
        $vatPercent = $settings['vat_on_accommodation'] ? (string) $settings['vat_percent'] : '0';

        $lines = Stay::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', StayStatus::Open->value)
            ->with(['reservationRoom', 'room'])
            ->get()
            ->map(function (Stay $stay) use ($percent, $scPercent, $vatPercent) {
                $unit = Money::percentOf(Money::toMinor($stay->reservationRoom->nightly_rate), (string) $percent);

                return [
                    'stay_id' => $stay->id,
                    'room_number' => $stay->room?->number,
                    'nightly_rate' => $stay->reservationRoom->nightly_rate,
                    'amounts' => ChargeAmounts::compute($unit, '1', $scPercent, $vatPercent),
                ];
            })->all();

        return [
            'percent' => $percent,
            'lines' => $lines,
            'total' => array_sum(array_map(fn ($l) => $l['amounts']->total(), $lines)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkOutPreview(Reservation $reservation): array
    {
        $today = $this->settings->today()->toDateString();
        $out = $reservation->check_out->toDateString();
        $late = $this->lateFee($reservation);
        $alreadyCharged = ReservationCharge::query()
            ->where('reservation_id', $reservation->id)
            ->where('category', ChargeCategory::LateCheckout->value)
            ->active()
            ->exists();
        $summary = $this->folio->summary($reservation);
        $balance = $reservation->balanceMinor();

        return [
            'status' => $reservation->status->value,
            'can_check_out' => $reservation->status === ReservationStatus::CheckedIn && $today <= $out,
            'overstay' => $reservation->status === ReservationStatus::CheckedIn && $today > $out,
            'early_departure' => $today < $out,
            'check_out_time' => $this->settings->get('check_out_time'),
            'late_fee' => [
                'already_charged' => $alreadyCharged,
                'percent' => $late['percent'],
                'total' => Money::toDecimal($late['total']),
                'rooms' => array_map(fn ($l) => [
                    'room_number' => $l['room_number'],
                    'nightly_rate' => $l['nightly_rate'],
                    'total' => Money::toDecimal($l['amounts']->total()),
                ], $late['lines']),
            ],
            'bill' => $summary,
            'balance_now' => Money::toDecimal($balance),
            'balance_with_late_fee' => Money::toDecimal($balance + ($alreadyCharged ? 0 : $late['total'])),
        ];
    }

    /**
     * @param  array{apply_late_fee?: bool, waive_reason?: ?string, override_balance?: bool, override_reason?: ?string}  $data
     * @return array{reservation: Reservation, statement: FolioStatement}
     */
    public function checkOut(Reservation $reservation, array $data, User $actor, bool $mayOverride): array
    {
        // Step 1 (committed on its own): the late fee goes on the bill even if
        // check-out then stops for payment, so the desk can take payment for it.
        DB::transaction(function () use ($reservation, $data, $actor) {
            $locked = $this->lock($reservation);
            $this->assertCanCheckOut($locked);
            $this->settleLateFee($locked, (bool) ($data['apply_late_fee'] ?? true), $data['waive_reason'] ?? null, $actor);
        });

        $result = DB::transaction(function () use ($reservation, $data, $actor, $mayOverride) {
            $locked = $this->lock($reservation);
            $this->assertCanCheckOut($locked);
            $today = $this->settings->today()->toDateString();
            $out = $locked->check_out->toDateString();

            // P18: no check-out with money owed unless a manager overrides.
            $balance = $locked->balanceMinor();

            if ($balance > 0) {
                if (! ($data['override_balance'] ?? false)) {
                    throw new BusinessRuleException(
                        'The guest still owes '.Money::format($balance).'. Take payment before check-out.',
                        'BALANCE_DUE',
                        409,
                        ['balance' => Money::toDecimal($balance)]
                    );
                }

                if (! $mayOverride) {
                    throw new BusinessRuleException('Only a manager can check a guest out with an unpaid balance.', 'FORBIDDEN', 403);
                }

                $locked->forceFill(['balance_at_checkout' => Money::toDecimal($balance)]);

                $this->audit->record('reservations.checked_out_with_balance', $locked, null, [
                    'balance' => Money::toDecimal($balance),
                ], ['reason' => $data['override_reason'] ?? null], $actor);
            }

            // P17: leaving early releases the remaining nights; booked nights stay charged.
            foreach ($locked->rooms()->where('is_active', true)->get() as $line) {
                $end = max($today, CarbonImmutable::parse($line->check_in->toDateString())->addDay()->toDateString());

                if ($end < $line->check_out->toDateString()) {
                    $line->forceFill(['check_out' => $end])->save();
                }
            }

            $stays = Stay::query()->where('reservation_id', $locked->id)->where('status', StayStatus::Open->value)->lockForUpdate()->get();

            foreach ($stays as $stay) {
                $stay->forceFill([
                    'status' => StayStatus::Closed,
                    'close_reason' => 'CHECKED_OUT',
                    'closed_at' => now(),
                    'closed_by' => $actor->id,
                ])->save();

                Room::query()->whereKey($stay->room_id)->update(['status' => RoomStatus::Dirty->value, 'updated_at' => now()]);
            }

            $locked->forceFill([
                'status' => ReservationStatus::CheckedOut,
                'checked_out_at' => now(),
                'checked_out_by' => $actor->id,
            ])->save();

            $statement = $this->folio->issueStatement($locked);

            $this->audit->record('reservations.checked_out', $locked, ['status' => 'CHECKED_IN'], ['status' => 'CHECKED_OUT'], [
                'statement' => $statement->number,
                'balance' => Money::toDecimal($locked->balanceMinor()),
                'early_departure' => $today < $out,
            ], $actor);

            return ['reservation' => $locked, 'statement' => $statement];
        });

        $this->folio->emailStatement($result['statement']);

        return $result;
    }

    private function assertCanCheckOut(Reservation $locked): void
    {
        if ($locked->status !== ReservationStatus::CheckedIn) {
            throw new BusinessRuleException('Only checked-in guests can be checked out.', 'INVALID_STATUS', 409);
        }

        if ($this->settings->today()->toDateString() > $locked->check_out->toDateString()) {
            throw new BusinessRuleException(
                'The guest was due to leave on '.$locked->check_out->format('j M').'. Extend the stay to today first so the extra nights are billed.',
                'OVERSTAY',
                409
            );
        }
    }

    /**
     * P4: posts the late check-out fee once, or waives it (voiding any fee
     * already posted) with a recorded reason.
     */
    private function settleLateFee(Reservation $locked, bool $apply, ?string $waiveReason, User $actor): void
    {
        $posted = ReservationCharge::query()
            ->where('reservation_id', $locked->id)
            ->where('category', ChargeCategory::LateCheckout->value)
            ->active()
            ->get();

        if (! $apply) {
            $late = $this->lateFee($locked);

            foreach ($posted as $charge) {
                $charge->forceFill(['status' => 'VOIDED', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $waiveReason])->save();
            }

            if ($posted->isNotEmpty()) {
                $this->folio->recalculate($locked);
            }

            if ($late['total'] > 0 || $posted->isNotEmpty()) {
                $this->audit->record('reservations.late_fee_waived', $locked, null, null, [
                    'amount' => Money::toDecimal(max($late['total'], $posted->sum(fn ($c) => Money::toMinor($c->total)))),
                    'reason' => $waiveReason,
                ], $actor);
            }

            return;
        }

        if ($posted->isNotEmpty()) {
            return; // already on the bill
        }

        $late = $this->lateFee($locked);

        foreach ($late['lines'] as $line) {
            $this->folio->post(
                $locked,
                ChargeCategory::LateCheckout,
                "Late check-out ({$late['percent']}%) – Room {$line['room_number']}",
                $line['amounts'],
                Stay::find($line['stay_id']),
                $actor,
            );
        }
    }

    private function lock(Reservation $reservation): Reservation
    {
        return Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
    }
}
