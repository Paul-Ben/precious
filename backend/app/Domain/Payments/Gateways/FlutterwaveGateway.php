<?php

namespace App\Domain\Payments\Gateways;

use App\Enums\GatewayMode;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave Standard (v3 hosted payment link).
 *
 * @see https://developer.flutterwave.com/v3.0/docs/flutterwave-standard-1
 * @see https://developer.flutterwave.com/v3.0/docs/webhooks
 */
class FlutterwaveGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    public function initialize(Payment $payment, PaymentGatewaySetting $setting, array $customer): InitializeResult
    {
        $response = $this->send(fn () => $this->client($payment, $setting)->post('/payments', [
            'tx_ref' => $payment->reference,
            // Flutterwave amounts are in naira.
            'amount' => $payment->charged_amount,
            'currency' => $payment->currency,
            'redirect_url' => $customer['callback_url'],
            'customer' => array_filter([
                'email' => $customer['email'],
                'name' => $customer['name'],
                'phonenumber' => $customer['phone'],
            ]),
            'customizations' => [
                'title' => config('app.name'),
                'description' => $customer['description'],
            ],
            'meta' => ['payment_id' => $payment->id],
        ]));

        $link = $response->json('data.link');

        if (! $response->successful() || $response->json('status') !== 'success' || ! is_string($link)) {
            throw new GatewayException('Flutterwave could not start the payment: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return new InitializeResult($link);
    }

    public function verify(Payment $payment, PaymentGatewaySetting $setting): VerificationResult
    {
        $response = $this->send(fn () => $this->client($payment, $setting)
            ->get('/transactions/verify_by_reference', ['tx_ref' => $payment->reference]));

        if (in_array($response->status(), [400, 404], true)) {
            return VerificationResult::pending($response->json('message'));
        }

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new GatewayException('Flutterwave verification failed: HTTP '.$response->status());
        }

        $data = (array) $response->json('data');

        return match ($data['status'] ?? null) {
            'successful' => new VerificationResult(
                VerificationResult::SUCCESS,
                amountMinor: $this->minor($data['amount'] ?? null),
                currency: $data['currency'] ?? null,
                transactionId: isset($data['id']) ? (string) $data['id'] : null,
                feeMinor: isset($data['app_fee']) ? $this->minor($data['app_fee']) : null,
                channel: $data['payment_type'] ?? null,
                paidAt: isset($data['created_at']) ? CarbonImmutable::parse($data['created_at']) : null,
                message: $data['processor_response'] ?? null,
            ),
            'failed' => VerificationResult::failed($data['processor_response'] ?? 'Payment was declined.'),
            default => VerificationResult::pending($data['processor_response'] ?? null),
        };
    }

    public function hasValidSignature(string $rawBody, array $headers, PaymentGatewaySetting $setting): bool
    {
        $signature = (string) ($headers['verif-hash'] ?? '');

        if ($signature === '') {
            return false;
        }

        foreach (GatewayMode::cases() as $mode) {
            $hash = $setting->credential('webhook_secret_hash', $mode);

            if ($hash && hash_equals($hash, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(array $payload): ?WebhookNotice
    {
        $event = (string) ($payload['event'] ?? $payload['event.type'] ?? '');
        $data = $payload['data'] ?? [];
        $reference = $data['tx_ref'] ?? $data['txRef'] ?? null;

        if (! in_array($event, ['charge.completed', 'CARD_TRANSACTION', 'BANK_TRANSFER_TRANSACTION'], true) || ! is_string($reference)) {
            return null;
        }

        return new WebhookNotice($event.':'.($data['id'] ?? $reference), $event, $reference);
    }

    /** Flutterwave returns JSON numbers (e.g. 30450.5); convert without float drift. */
    private function minor(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::toMinor(number_format((float) $value, 2, '.', ''));
    }

    private function client(Payment $payment, PaymentGatewaySetting $setting): PendingRequest
    {
        $mode = $payment->gateway_mode ?? $setting->mode;
        $secret = $setting->credential('secret_key', $mode) ?? throw new GatewayException("Flutterwave {$mode->value} secret key is not configured.");

        return Http::baseUrl(self::BASE_URL)
            ->withToken($secret)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('payments.http_timeout', 20));
    }

    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new GatewayException('Could not reach Flutterwave: '.$e->getMessage(), previous: $e);
        }
    }
}
