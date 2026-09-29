<?php

namespace App\Domain\Stays;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\ReservationLedger;
use App\Enums\ChargeCategory;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DocumentSequence;
use App\Models\FolioStatement;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationCharge;
use App\Models\Service;
use App\Models\Stay;
use App\Models\User;
use App\Notifications\FolioStatementNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The guest bill (spec §19-20): booked accommodation + charges − payments.
 * Charges are only added while the guest is in house (spec §28: only active
 * stays receive charges); voids keep the line for the audit trail.
 */
class FolioService
{
    public function __construct(
        private readonly HotelSettings $settings,
        private readonly ReservationLedger $ledger,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{service_id?: ?int, category?: ?string, description?: ?string, quantity?: ?string,
     *     unit_price?: ?string, amount?: ?string, stay_id?: ?string, reason?: ?string,
     *     charges_vat?: ?bool, charges_service_charge?: ?bool}  $data
     */
    public function addCharge(Reservation $reservation, array $data, User $actor): ReservationCharge
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            $locked = $this->lock($reservation);

            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw new BusinessRuleException('Charges can only be added while the guest is checked in.', 'NOT_IN_HOUSE', 409);
            }

            $stay = $this->resolveStay($locked, $data['stay_id'] ?? null);
            $settings = $this->settings->all();
            $quantity = (string) ($data['quantity'] ?? '1');

            if (! empty($data['service_id'])) {
                /** @var Service $service */
                $service = Service::query()->where('property_id', Property::current()->id)->active()->findOrFail($data['service_id']);
                $amounts = ChargeAmounts::compute(
                    Money::toMinor($service->price),
                    $quantity,
                    $service->charges_service_charge ? (string) $settings['service_charge_percent'] : '0',
                    $service->charges_vat ? (string) $settings['vat_percent'] : '0',
                );

                return $this->post($locked, ChargeCategory::Service, ($data['description'] ?? null) ?: $service->name, $amounts, $stay, $actor, $service->id, $data['reason'] ?? null);
            }

            $category = ChargeCategory::from($data['category'] ?? ChargeCategory::Other->value);

            if ($category === ChargeCategory::Adjustment) {
                // A reduction entered as the full amount off the bill (tax included).
                $amounts = ChargeAmounts::compute(-Money::toMinor((string) $data['amount']), '1', '0', '0');

                return $this->post($locked, $category, ($data['description'] ?? null) ?: 'Adjustment', $amounts, $stay, $actor, null, $data['reason'] ?? null);
            }

            if (! in_array($category, [ChargeCategory::Other, ChargeCategory::Service], true)) {
                throw new BusinessRuleException('That charge type is added automatically.', 'INVALID_CATEGORY', 422);
            }

            $amounts = ChargeAmounts::compute(
                Money::toMinor((string) $data['unit_price']),
                $quantity,
                ($data['charges_service_charge'] ?? true) ? (string) $settings['service_charge_percent'] : '0',
                ($data['charges_vat'] ?? true) ? (string) $settings['vat_percent'] : '0',
            );

            return $this->post($locked, $category, (string) $data['description'], $amounts, $stay, $actor, null, $data['reason'] ?? null);
        });
    }

    public function voidCharge(ReservationCharge $charge, string $reason, User $actor): ReservationCharge
    {
        return DB::transaction(function () use ($charge, $reason, $actor) {
            $reservation = $this->lock($charge->reservation);

            /** @var ReservationCharge $locked */
            $locked = ReservationCharge::query()->whereKey($charge->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw new BusinessRuleException('This charge is already voided.', 'INVALID_STATUS', 422);
            }

            if ($locked->category === ChargeCategory::Bar) {
                // The bar bill is closed as "charged to room"; voiding here would leave it unbilled.
                throw new BusinessRuleException('Bar charges cannot be voided here. Ask a manager to add a reduction instead.', 'BAR_CHARGE', 422);
            }

            if ($reservation->status !== ReservationStatus::CheckedIn) {
                throw new BusinessRuleException('The bill is closed; charges can only be voided while the guest is checked in.', 'NOT_IN_HOUSE', 409);
            }

            $locked->forceFill([
                'status' => 'VOIDED',
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ])->save();

            $this->recalculate($reservation);

            $this->audit->record('folio.charge_voided', $locked, ['status' => 'ACTIVE'], ['status' => 'VOIDED'], [
                'reservation' => $reservation->number,
                'description' => $locked->description,
                'total' => $locked->total,
                'reason' => $reason,
            ], $actor);

            return $locked->refresh();
        });
    }

    /**
     * Adds a line and refreshes the bill total. Caller holds the reservation lock.
     */
    public function post(
        Reservation $reservation,
        ChargeCategory $category,
        string $description,
        ChargeAmounts $amounts,
        ?Stay $stay,
        ?User $actor,
        ?int $serviceId = null,
        ?string $reason = null,
    ): ReservationCharge {
        $charge = ReservationCharge::create([
            'reservation_id' => $reservation->id,
            'stay_id' => $stay?->id,
            'service_id' => $serviceId,
            'category' => $category,
            'description' => mb_substr($description, 0, 255),
            'quantity' => $amounts->quantity,
            'unit_price' => Money::toDecimal($amounts->unit),
            'subtotal' => Money::toDecimal($amounts->subtotal),
            'service_charge' => Money::toDecimal($amounts->serviceCharge),
            'vat' => Money::toDecimal($amounts->vat),
            'total' => Money::toDecimal($amounts->total()),
            'status' => 'ACTIVE',
            'reason' => $reason,
            'created_by' => $actor?->id,
        ]);

        $this->recalculate($reservation);

        $this->audit->record('folio.charge_added', $charge, null, [
            'category' => $category->value,
            'description' => $charge->description,
            'total' => $charge->total,
        ], array_filter(['reservation' => $reservation->number, 'reason' => $reason]), $actor);

        return $charge;
    }

    /** Recomputes charges_total and the payment status from the active lines. */
    public function recalculate(Reservation $reservation): void
    {
        $sum = ReservationCharge::query()
            ->where('reservation_id', $reservation->id)
            ->active()
            ->pluck('total')
            ->sum(fn ($t) => Money::toMinor((string) $t));

        $reservation->forceFill(['charges_total' => Money::toDecimal($sum)])->save();
        $this->ledger->recalculate($reservation);
    }

    /**
     * Bill breakdown used by the check-out screen and the final statement.
     *
     * @return array<string, mixed>
     */
    public function summary(Reservation $reservation): array
    {
        $reservation->load(['charges', 'rooms.room', 'rooms.roomType']);
        $payments = Payment::query()
            ->where('payable_type', $reservation->getMorphClass())
            ->where('payable_id', $reservation->id)
            ->where('status', TransactionStatus::Successful->value)
            ->with('receipt')
            ->orderBy('paid_at')
            ->get();

        return [
            'accommodation' => [
                'subtotal' => $reservation->subtotal,
                'service_charge' => $reservation->service_charge_total,
                'vat' => $reservation->tax_total,
                'total' => $reservation->total,
                'nights' => $reservation->nights,
                'rooms' => $reservation->rooms->where('is_active', true)->map(fn ($line) => [
                    'room_number' => $line->room?->number,
                    'room_type' => $line->roomType?->name,
                    'check_in' => $line->check_in->toDateString(),
                    'check_out' => $line->check_out->toDateString(),
                    'nightly_rate' => $line->nightly_rate,
                ])->values()->all(),
            ],
            'charges' => $reservation->charges->where('status', 'ACTIVE')->map(fn (ReservationCharge $c) => [
                'id' => $c->id,
                'category' => $c->category->value,
                'description' => $c->description,
                'quantity' => $c->quantity,
                'unit_price' => $c->unit_price,
                'subtotal' => $c->subtotal,
                'service_charge' => $c->service_charge,
                'vat' => $c->vat,
                'total' => $c->total,
                'date' => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'charges_total' => Money::toDecimal(Money::toMinor($reservation->charges_total ?? '0')),
            'grand_total' => Money::toDecimal($reservation->grandTotalMinor()),
            'payments' => $payments->map(fn (Payment $p) => [
                'date' => $p->paid_at?->toIso8601String(),
                'method' => $p->method->label(),
                'receipt_number' => $p->receipt?->number,
                'amount' => $p->amount,
                'refunded' => $p->refunded_amount,
            ])->values()->all(),
            'paid' => $reservation->amount_paid,
            'balance' => Money::toDecimal($reservation->balanceMinor()),
        ];
    }

    /** Final bill issued at check-out (FOL-YYYY-NNNNN). Caller holds the lock. */
    public function issueStatement(Reservation $reservation): FolioStatement
    {
        $property = Property::current();
        $reservation->loadMissing('guest');

        return FolioStatement::create([
            'number' => DocumentSequence::next('FOL', (int) $this->settings->today()->year),
            'reservation_id' => $reservation->id,
            'issued_at' => now(),
            'snapshot' => [
                'hotel' => [
                    'name' => $property->name,
                    'legal_name' => $property->legal_name,
                    'address' => collect([$property->address, $property->city, $property->state])->filter()->implode(', '),
                    'phone' => $property->phone,
                    'email' => $property->email,
                ],
                'guest' => [
                    'name' => $reservation->guest?->fullName(),
                    'email' => $reservation->guest?->email,
                    'phone' => $reservation->guest?->phone,
                ],
                'reservation' => [
                    'number' => $reservation->number,
                    'check_in' => $reservation->check_in->toDateString(),
                    'check_out' => $reservation->check_out->toDateString(),
                    'checked_in_at' => $reservation->checked_in_at?->toIso8601String(),
                    'checked_out_at' => ($reservation->checked_out_at ?? now())->toIso8601String(),
                ],
                ...$this->summary($reservation),
            ],
        ]);
    }

    public function emailStatement(FolioStatement $statement, ?string $to = null): bool
    {
        $to ??= $statement->snapshot['guest']['email'] ?? null;

        if (! $to) {
            return false;
        }

        try {
            Notification::route('mail', $to)->notify(new FolioStatementNotification($statement));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $statement->forceFill(['emailed_at' => now()])->save();

        return true;
    }

    private function resolveStay(Reservation $reservation, ?string $stayId): ?Stay
    {
        $open = Stay::query()->where('reservation_id', $reservation->id)->where('status', StayStatus::Open->value);

        if ($stayId) {
            return (clone $open)->whereKey($stayId)->first()
                ?? throw new BusinessRuleException('That room is not part of this stay.', 'INVALID_STAY', 422);
        }

        return $open->count() === 1 ? $open->first() : null;
    }

    private function lock(Reservation $reservation): Reservation
    {
        return Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
    }
}
