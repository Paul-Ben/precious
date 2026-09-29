<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationCharge;
use App\Models\ReservationRoom;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    private bool $staffView = false;

    public function forStaff(bool $staff = true): static
    {
        $this->staffView = $staff;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'source' => $this->source->value,
            'check_in' => $this->check_in->toDateString(),
            'check_out' => $this->check_out->toDateString(),
            'nights' => $this->nights,
            'adults' => $this->adults,
            'children' => $this->children,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'service_charge_total' => $this->service_charge_total,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            // Extras added during the stay; grand_total = total + charges_total.
            'charges_total' => Money::toDecimal(Money::toMinor($this->charges_total ?? '0')),
            'grand_total' => Money::toDecimal($this->grandTotalMinor()),
            'deposit_percent' => $this->deposit_percent,
            'deposit_amount' => $this->deposit_amount,
            'amount_paid' => $this->amount_paid,
            'balance' => Money::toDecimal($this->balanceMinor()),
            'pricing' => $this->pricing_snapshot,
            'free_cancellation_hours' => $this->free_cancellation_hours,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'balance_at_checkout' => $this->when($this->staffView, $this->balance_at_checkout),
            'cancellation' => $this->cancelled_at ? [
                'cancelled_at' => $this->cancelled_at->toIso8601String(),
                'reason' => $this->cancellation_reason,
                'refund_eligible' => $this->refund_eligible,
            ] : null,
            'special_requests' => $this->special_requests,
            'internal_notes' => $this->when($this->staffView, $this->internal_notes),
            'guest' => $this->whenLoaded('guest', fn () => [
                'id' => $this->guest->id,
                'full_name' => $this->guest->fullName(),
                'email' => $this->guest->email,
                'phone' => $this->guest->phone,
            ]),
            'guests' => $this->whenLoaded('guests', fn () => $this->guests->map(fn ($g) => [
                'id' => $g->id,
                'full_name' => $g->fullName(),
                'is_primary' => (bool) $g->pivot->is_primary,
            ])->values()),
            'rooms' => $this->whenLoaded('rooms', fn () => $this->rooms->map(fn (ReservationRoom $line) => [
                'id' => $line->id,
                'room_id' => $line->room_id,
                // Guests see the room type; the room number is assigned at check-in desk.
                'room_number' => $this->staffView ? $line->room?->number : null,
                'room_type' => ['id' => $line->room_type_id, 'name' => $line->roomType?->name],
                'adults' => $line->adults,
                'children' => $line->children,
                'nightly_rate' => $line->nightly_rate,
                'nights' => $line->nights,
                'subtotal' => $line->subtotal,
                'is_active' => $line->is_active,
            ])->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments
                // Guests see money that actually arrived; staff see every attempt.
                ->filter(fn (Payment $p) => $this->staffView || $p->isSuccessful())
                ->map(fn (Payment $p) => (new PaymentResource($p))->forStaff($this->staffView)->resolve($request))
                ->values()),
            'charges' => $this->whenLoaded('charges', fn () => $this->charges
                ->filter(fn (ReservationCharge $c) => $this->staffView || $c->isActive())
                ->map(fn (ReservationCharge $c) => (new ChargeResource($c))->resolve($request))
                ->values()),
            'stays' => $this->when($this->staffView && $this->relationLoaded('stays'), fn () => StayResource::collection($this->stays)->resolve($request)),
            'folio_number' => $this->whenLoaded('folioStatement', fn () => $this->folioStatement?->number),
            'booked_by' => $this->when($this->staffView, fn () => $this->bookedBy ? ['id' => $this->bookedBy->id, 'name' => $this->bookedBy->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
