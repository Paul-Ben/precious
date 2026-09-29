<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditService;
use App\Domain\Payments\Gateways\GatewayException;
use App\Domain\Payments\Gateways\VerificationResult;
use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\ReservationLedger;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\ReservationStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\Property;
use App\Models\Receipt;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Online (Paystack / Flutterwave) and desk (cash / POS / transfer) payments
 * for reservations.
 *
 * Rules (spec §15, ASSUMPTIONS P5/P8/P13):
 *  - money is only credited after the gateway confirms it server-side
 *    (redirects and webhooks are just hints to go and verify);
 *  - each payment is credited once, whatever order redirect, webhook and
 *    reconciliation arrive in (row lock + final-status check);
 *  - the payer covers the gateway fee when the hotel policy says so;
 *  - a successful payment always gets a numbered receipt.
 */
class PaymentService
{
    /** Reservation statuses that can still take money. */
    private const PAYABLE = [
        ReservationStatus::PendingPayment,
        ReservationStatus::Confirmed,
        ReservationStatus::PartiallyPaid,
        ReservationStatus::CheckInPending,
        ReservationStatus::CheckedIn,
        ReservationStatus::CheckedOut,
    ];

    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly ReservationLedger $ledger,
        private readonly ReceiptService $receipts,
        private readonly HotelSettings $settings,
        private readonly AuditService $audit,
    ) {}

    // ------------------------------------------------------------------ Options

    /**
     * What the payer can pay now, with the processing fee per enabled gateway.
     *
     * @return array{payable: bool, reason: ?string, fees_passed_to_customer: bool, options: list<array<string, mixed>>, gateways: list<array<string, mixed>>}
     */
    public function options(Reservation $reservation): array
    {
        $reason = $this->unpayableReason($reservation);
        $passFees = (bool) $this->settings->get('pass_gateway_fees_to_customer');
        $enabled = $this->gateways->enabled();

        $options = [];

        if ($reason === null) {
            foreach ($this->amounts($reservation) as $key => [$purpose, $minor]) {
                $options[] = [
                    'option' => $key,
                    'purpose' => $purpose->value,
                    'label' => match ($key) {
                        'deposit' => 'Pay deposit ('.rtrim(rtrim($reservation->deposit_percent, '0'), '.').'%)',
                        default => Money::toMinor($reservation->amount_paid) > 0 ? 'Pay balance' : 'Pay in full',
                    },
                    'amount' => Money::toDecimal($minor),
                    'by_gateway' => $enabled->map(function (PaymentGatewaySetting $setting) use ($minor, $passFees) {
                        $fee = $passFees ? FeeSchedule::forSetting($setting)->grossUp($minor) - $minor : 0;

                        return [
                            'gateway' => $setting->gateway,
                            'fee' => Money::toDecimal($fee),
                            'total' => Money::toDecimal($minor + $fee),
                        ];
                    })->values()->all(),
                ];
            }
        }

        return [
            'payable' => $reason === null && $options !== [],
            'reason' => $reason ?? ($options === [] ? 'Nothing is due on this reservation.' : null),
            'fees_passed_to_customer' => $passFees,
            'options' => $options,
            'gateways' => $enabled->map(fn (PaymentGatewaySetting $s) => [
                'gateway' => $s->gateway,
                'name' => $s->display_name ?: GatewayRegistry::get($s->gateway)['name'],
                'is_default' => $s->is_default,
                'test_mode' => $s->mode->value === 'test',
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, array{0: PaymentPurpose, 1: int}> option => [purpose, amount in kobo]
     */
    private function amounts(Reservation $reservation): array
    {
        $balance = $reservation->balanceMinor();
        $paid = Money::toMinor($reservation->amount_paid);

        if ($balance <= 0) {
            return [];
        }

        $amounts = [];
        $depositDue = Money::toMinor($reservation->deposit_amount) - $paid;

        if ($reservation->status === ReservationStatus::PendingPayment && $depositDue > 0 && $depositDue < $balance) {
            $amounts['deposit'] = [PaymentPurpose::Deposit, $depositDue];
        }

        $amounts['balance'] = [$paid > 0 ? PaymentPurpose::Balance : PaymentPurpose::Full, $balance];

        return $amounts;
    }

    private function unpayableReason(Reservation $reservation): ?string
    {
        if (! in_array($reservation->status, self::PAYABLE, true)) {
            return match ($reservation->status) {
                ReservationStatus::Expired => 'This reservation expired before it was paid. Please make a new booking.',
                ReservationStatus::Cancelled => 'This reservation has been cancelled.',
                default => 'This reservation cannot be paid online.',
            };
        }

        if ($reservation->status === ReservationStatus::PendingPayment && $reservation->expires_at?->isPast()) {
            return 'The hold on these rooms has expired. Please make a new booking.';
        }

        return null;
    }

    // --------------------------------------------------------------- Online pay

    /**
     * Creates a PENDING payment and a hosted checkout page at the gateway.
     */
    public function initiate(Reservation $reservation, string $option, ?string $gateway, ?User $actor = null, ?string $email = null): Payment
    {
        $setting = $this->gateways->choose($gateway);

        $payment = DB::transaction(function () use ($reservation, $option, $setting, $actor, $email) {
            /** @var Reservation $locked */
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $locked->load('guest');

            if ($reason = $this->unpayableReason($locked)) {
                throw new BusinessRuleException($reason, 'NOT_PAYABLE', 409);
            }

            $amounts = $this->amounts($locked);

            if (! isset($amounts[$option])) {
                throw new BusinessRuleException('That payment option is not available for this reservation.', 'INVALID_PAYMENT_OPTION', 422);
            }

            [$purpose, $minor] = $amounts[$option];
            $payerEmail = $email ?: $locked->guest?->email ?: $actor?->email;

            if (! $payerEmail) {
                throw new BusinessRuleException('An email address is needed to pay online.', 'EMAIL_REQUIRED', 422);
            }

            $fee = $this->settings->get('pass_gateway_fees_to_customer')
                ? FeeSchedule::forSetting($setting)->grossUp($minor) - $minor
                : 0;

            $this->extendHold($locked);

            $payment = Payment::create([
                'reference' => 'PAY-'.Str::upper((string) Str::ulid()),
                'property_id' => $locked->property_id,
                'payable_type' => $locked->getMorphClass(),
                'payable_id' => $locked->id,
                'guest_id' => $locked->guest_id,
                'method' => PaymentMethod::Gateway,
                'gateway' => $setting->gateway,
                'gateway_mode' => $setting->mode,
                'purpose' => $purpose,
                'status' => TransactionStatus::Pending,
                'currency' => $locked->currency,
                'amount' => Money::toDecimal($minor),
                'customer_fee' => Money::toDecimal($fee),
                'charged_amount' => Money::toDecimal($minor + $fee),
                'payer_email' => $payerEmail,
            ]);

            $this->audit->record('payments.initiated', $payment, null, [
                'reference' => $payment->reference,
                'reservation' => $locked->number,
                'gateway' => $setting->gateway,
                'mode' => $setting->mode->value,
                'amount' => $payment->amount,
                'customer_fee' => $payment->customer_fee,
            ], [], $actor);

            return $payment;
        });

        $reservation->loadMissing('guest');

        try {
            $result = $this->gateways->driver($setting->gateway)->initialize($payment, $setting, [
                'email' => $payment->payer_email,
                'name' => $reservation->guest?->fullName() ?? 'Guest',
                'phone' => $reservation->guest?->phone,
                'description' => 'Reservation '.$reservation->number,
                'callback_url' => config('security.frontend_url').'/pay/callback',
            ]);
        } catch (GatewayException $e) {
            report($e);
            $payment->forceFill(['status' => TransactionStatus::Failed, 'failure_reason' => Str::limit($e->getMessage(), 250)])->save();

            throw new BusinessRuleException(
                'We could not reach the payment provider. Please try again in a moment.',
                'GATEWAY_UNAVAILABLE',
                502
            );
        }

        $payment->forceFill(['authorization_url' => $result->authorizationUrl])->save();

        return $payment;
    }

    /**
     * Keeps an unpaid hold alive while the payer is on the gateway page
     * (at most config('payments.max_hold_extensions') times per reservation).
     */
    private function extendHold(Reservation $reservation): void
    {
        if ($reservation->status !== ReservationStatus::PendingPayment || ! $reservation->expires_at) {
            return;
        }

        $minimum = now()->addMinutes((int) config('payments.hold_extension_minutes', 15));

        if ($reservation->expires_at->gte($minimum)) {
            return;
        }

        $attempts = Payment::query()
            ->where('payable_type', $reservation->getMorphClass())
            ->where('payable_id', $reservation->id)
            ->where('method', PaymentMethod::Gateway->value)
            ->count();

        if ($attempts < (int) config('payments.max_hold_extensions', 3)) {
            $reservation->forceFill(['expires_at' => $minimum])->save();
        }
    }

    /**
     * Asks the gateway for the truth about a pending payment and settles it.
     * Safe to call any number of times, from anywhere.
     */
    public function verify(Payment $payment): Payment
    {
        if ($payment->method !== PaymentMethod::Gateway || $payment->status->isFinal()) {
            return $payment;
        }

        $setting = $this->gateways->setting($payment->gateway);

        try {
            $result = $this->gateways->driver($payment->gateway)->verify($payment, $setting);
        } catch (GatewayException $e) {
            Log::warning('Payment verification failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);
            $payment->forceFill(['verify_attempts' => $payment->verify_attempts + 1, 'last_verified_at' => now()])->save();

            return $payment;
        }

        return $this->settle($payment, $result);
    }

    public function settle(Payment $payment, VerificationResult $result): Payment
    {
        $receipt = DB::transaction(function () use ($payment, $result): ?Receipt {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isFinal()) {
                return null; // already handled by another path
            }

            $locked->forceFill([
                'verify_attempts' => $locked->verify_attempts + 1,
                'last_verified_at' => now(),
            ]);

            if ($result->state === VerificationResult::PENDING) {
                $locked->save();

                return null;
            }

            if ($result->state === VerificationResult::FAILED) {
                $locked->forceFill([
                    'status' => TransactionStatus::Failed,
                    'failure_reason' => Str::limit($result->message ?? 'Payment was not completed.', 250),
                ])->save();

                $this->audit->record('payments.failed', $locked, null, ['status' => 'FAILED'], ['reference' => $locked->reference, 'message' => $result->message]);

                return null;
            }

            // Success - but only credit exactly what we asked for, in our currency.
            if ($result->currency !== $locked->currency || $result->amountMinor === null || $result->amountMinor < Money::toMinor($locked->charged_amount)) {
                $locked->forceFill([
                    'status' => TransactionStatus::Failed,
                    'needs_attention' => true,
                    'attention_reason' => 'AMOUNT_MISMATCH',
                    'gateway_transaction_id' => $result->transactionId,
                    'failure_reason' => sprintf('Gateway reported %s %s, expected %s %s.',
                        $result->currency ?? '?', $result->amountMinor === null ? '?' : Money::toDecimal($result->amountMinor),
                        $locked->currency, $locked->charged_amount),
                ])->save();

                $this->audit->record('payments.amount_mismatch', $locked, null, null, ['reference' => $locked->reference, 'reported' => $result->amountMinor, 'currency' => $result->currency]);

                return null;
            }

            $locked->forceFill([
                'status' => TransactionStatus::Successful,
                'paid_at' => $result->paidAt ?? now(),
                'gateway_transaction_id' => $result->transactionId,
                'gateway_fee' => $result->feeMinor === null ? null : Money::toDecimal($result->feeMinor),
                'channel' => $result->channel,
            ])->save();

            return $this->credit($locked, null);
        });

        if ($receipt) {
            $this->receipts->email($receipt);
        }

        return $payment->refresh();
    }

    /**
     * Applies a successful payment to its reservation and issues the receipt.
     * Runs inside the caller's transaction.
     */
    private function credit(Payment $payment, ?User $actor): Receipt
    {
        /** @var Reservation $reservation */
        $reservation = Reservation::query()->whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();

        $attention = $this->ledger->credit($reservation, $payment);

        if ($attention !== null) {
            $payment->forceFill(['needs_attention' => true, 'attention_reason' => $attention])->save();
        }

        $this->audit->record('payments.succeeded', $payment, null, [
            'status' => 'SUCCESSFUL',
            'amount' => $payment->amount,
            'method' => $payment->method->value,
        ], array_filter([
            'reference' => $payment->reference,
            'reservation' => $reservation->number,
            'attention' => $attention,
        ]), $actor);

        return $this->receipts->issue($payment->load('recordedBy'), $reservation->load('guest'));
    }

    // ------------------------------------------------------------ Desk payments

    /**
     * Cash, POS or bank transfer taken by staff.
     *
     * @param  array{method: string, amount: string, external_reference?: ?string, note?: ?string, send_receipt?: bool}  $data
     */
    public function recordManual(Reservation $reservation, array $data, User $actor): Payment
    {
        $method = PaymentMethod::from($data['method']);

        if ($method === PaymentMethod::Gateway) {
            throw new BusinessRuleException('Online payments are recorded automatically.', 'INVALID_METHOD', 422);
        }

        $minor = Money::toMinor($data['amount']);

        [$payment, $receipt] = DB::transaction(function () use ($reservation, $data, $method, $minor, $actor) {
            /** @var Reservation $locked */
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow, ReservationStatus::Draft], true)) {
                throw new BusinessRuleException("A {$locked->status->value} reservation cannot take payments.", 'NOT_PAYABLE', 409);
            }

            $balance = $locked->balanceMinor();

            if ($minor <= 0 || $minor > $balance) {
                throw new BusinessRuleException(
                    'The amount must be between ₦0.01 and the balance of '.Money::format(max(0, $balance)).'.',
                    'INVALID_AMOUNT',
                    422,
                    ['balance' => Money::toDecimal(max(0, $balance))]
                );
            }

            $paid = Money::toMinor($locked->amount_paid);
            $depositDue = Money::toMinor($locked->deposit_amount) - $paid;

            $purpose = match (true) {
                $minor === $balance => $paid > 0 ? PaymentPurpose::Balance : PaymentPurpose::Full,
                $locked->status === ReservationStatus::PendingPayment && $depositDue > 0 && $minor >= $depositDue => PaymentPurpose::Deposit,
                default => PaymentPurpose::Part,
            };

            $payment = Payment::create([
                'reference' => 'PAY-'.Str::upper((string) Str::ulid()),
                'property_id' => $locked->property_id,
                'payable_type' => $locked->getMorphClass(),
                'payable_id' => $locked->id,
                'guest_id' => $locked->guest_id,
                'method' => $method,
                'purpose' => $purpose,
                'status' => TransactionStatus::Successful,
                'currency' => $locked->currency,
                'amount' => Money::toDecimal($minor),
                'customer_fee' => '0.00',
                'charged_amount' => Money::toDecimal($minor),
                'external_reference' => $data['external_reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('payments.recorded', $payment, null, [
                'method' => $method->value,
                'amount' => $payment->amount,
                'external_reference' => $payment->external_reference,
            ], ['reservation' => $locked->number], $actor);

            return [$payment, $this->credit($payment, $actor)];
        });

        if ((bool) ($data['send_receipt'] ?? true)) {
            $this->receipts->email($receipt);
        }

        return $payment->refresh();
    }

    /** Staff note that a flagged payment (late, cancelled, overpaid) was dealt with. */
    public function resolveAttention(Payment $payment, string $note, User $actor): Payment
    {
        if (! $payment->needs_attention) {
            return $payment;
        }

        $old = $payment->attention_reason;
        $payment->forceFill(['needs_attention' => false])->save();

        $this->audit->record('payments.attention_resolved', $payment, ['attention_reason' => $old], ['needs_attention' => false], ['note' => $note], $actor);

        return $payment;
    }

    // ----------------------------------------------------------------- Webhooks

    /**
     * @param  array<string, string|null>  $headers  lower-cased
     * @return string outcome: invalid_signature | ignored | duplicate | unknown_reference | <payment status>
     */
    public function handleWebhook(string $gateway, string $rawBody, array $headers): string
    {
        $setting = $this->gateways->setting($gateway);
        $driver = $this->gateways->driver($gateway);

        if (! $setting || ! $driver->hasValidSignature($rawBody, $headers, $setting)) {
            Log::warning('Rejected payment webhook with an invalid signature', ['gateway' => $gateway]);

            return 'invalid_signature';
        }

        $payload = json_decode($rawBody, true);
        $notice = is_array($payload) ? $driver->parseWebhook($payload) : null;

        if (! $notice) {
            return 'ignored';
        }

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'gateway' => $gateway,
            'event_key' => Str::limit($notice->eventKey, 150, ''),
            'event_type' => $notice->eventType,
            'reference' => Str::limit($notice->reference, 64, ''),
            'payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = WebhookEvent::query()->where('gateway', $gateway)->where('event_key', Str::limit($notice->eventKey, 150, ''))->first();

        if ($inserted === 0 && $event?->processed_at) {
            return 'duplicate';
        }

        $payment = Payment::query()->where('reference', $notice->reference)->where('gateway', $gateway)->first();

        if (! $payment) {
            $event?->forceFill(['processed_at' => now(), 'result' => 'unknown_reference'])->save();

            return 'unknown_reference';
        }

        // Never trust the payload: ask the gateway directly.
        $payment = $this->verify($payment);

        $event?->forceFill(['processed_at' => now(), 'result' => $payment->status->value])->save();

        return $payment->status->value;
    }

    // ------------------------------------------------------------ Reconciliation

    /**
     * Settles gateway payments whose redirect and webhook never arrived.
     *
     * @return array{verified: int, abandoned: int}
     */
    public function reconcile(): array
    {
        $abandonBefore = now()->subHours((int) config('payments.abandon_after_hours', 48));
        $checkBefore = now()->subMinutes((int) config('payments.reconcile_after_minutes', 5));

        $abandoned = Payment::query()
            ->where('method', PaymentMethod::Gateway->value)
            ->where('status', TransactionStatus::Pending->value)
            ->where('created_at', '<', $abandonBefore)
            ->update(['status' => TransactionStatus::Abandoned->value, 'updated_at' => now()]);

        $verified = 0;

        Payment::query()
            ->where('method', PaymentMethod::Gateway->value)
            ->where('status', TransactionStatus::Pending->value)
            ->where('created_at', '<', $checkBefore)
            ->where(fn ($q) => $q->whereNull('last_verified_at')->orWhere('last_verified_at', '<', now()->subMinutes(5)))
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->each(function (Payment $payment) use (&$verified) {
                $this->verify($payment);
                $verified++;
            });

        return ['verified' => $verified, 'abandoned' => $abandoned];
    }

    /** Current property's payments for a reservation, newest first. */
    public function forReservation(Reservation $reservation)
    {
        return Payment::query()
            ->where('payable_type', $reservation->getMorphClass())
            ->where('payable_id', $reservation->id)
            ->where('property_id', Property::current()->id)
            ->with(['receipt', 'recordedBy', 'refunds'])
            ->orderByDesc('created_at')
            ->get();
    }
}
