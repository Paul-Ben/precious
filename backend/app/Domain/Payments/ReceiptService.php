<?php

namespace App\Domain\Payments;

use App\Domain\Property\HotelSettings;
use App\Models\DocumentSequence;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Receipt;
use App\Models\Reservation;
use App\Notifications\PaymentReceiptNotification;
use App\Support\Money;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Issues a numbered receipt (P13: RCP-YYYY-NNNNNN) for every successful
 * payment. The content is frozen at issue time so later edits to the guest
 * or hotel details never change a receipt already given out.
 */
class ReceiptService
{
    public function __construct(private readonly HotelSettings $settings) {}

    /** Must run inside the transaction that marks the payment successful. */
    public function issue(Payment $payment, Reservation $reservation): Receipt
    {
        $property = Property::current();
        $guest = $reservation->guest;
        $issuedAt = now();

        return Receipt::create([
            'number' => DocumentSequence::next('RCP', (int) $this->settings->today()->year, 6),
            'payment_id' => $payment->id,
            'issued_at' => $issuedAt,
            'snapshot' => [
                'hotel' => [
                    'name' => $property->name,
                    'legal_name' => $property->legal_name,
                    'address' => collect([$property->address, $property->city, $property->state])->filter()->implode(', '),
                    'phone' => $property->phone,
                    'email' => $property->email,
                ],
                'issued_at' => $issuedAt->toIso8601String(),
                'received_from' => $guest?->fullName(),
                'guest_email' => $payment->payer_email ?? $guest?->email,
                'for' => [
                    'type' => 'reservation',
                    'number' => $reservation->number,
                    'check_in' => $reservation->check_in->toDateString(),
                    'check_out' => $reservation->check_out->toDateString(),
                    'nights' => $reservation->nights,
                ],
                'payment' => [
                    'reference' => $payment->reference,
                    'method' => $payment->method->value,
                    'method_label' => $payment->method->label(),
                    'gateway' => $payment->gateway,
                    'channel' => $payment->channel,
                    'external_reference' => $payment->external_reference,
                    'purpose' => $payment->purpose->value,
                    'paid_at' => ($payment->paid_at ?? $issuedAt)->toIso8601String(),
                ],
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'processing_fee' => $payment->customer_fee,
                'total_charged' => $payment->charged_amount,
                'reservation_total' => Money::toDecimal($reservation->grandTotalMinor()),
                'total_paid_to_date' => $reservation->amount_paid,
                'balance_after' => Money::toDecimal(max(0, $reservation->balanceMinor())),
                'received_by' => $payment->recordedBy?->name,
            ],
        ]);
    }

    /** Emails the receipt; failures are logged, never thrown at the payer. */
    public function email(Receipt $receipt, ?string $to = null): bool
    {
        $to ??= $receipt->snapshot['guest_email'] ?? null;

        if (! $to) {
            return false;
        }

        try {
            Notification::route('mail', $to)->notify(new PaymentReceiptNotification($receipt));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        $receipt->forceFill(['emailed_at' => now()])->save();

        return true;
    }
}
