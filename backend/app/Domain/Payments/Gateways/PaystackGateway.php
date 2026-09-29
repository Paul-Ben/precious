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
 * Paystack Standard (redirect) checkout.
 *
 * @see https://paystack.com/docs/api/transaction/
 * @see https://paystack.com/docs/payments/webhooks/
 */
class PaystackGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function initialize(Payment $payment, PaymentGatewaySetting $setting, array $customer): InitializeResult
    {
        $response = $this->send(fn () => $this->client($payment, $setting)->post('/transaction/initialize', [
            'email' => $customer['email'],
            // Paystack amounts are in kobo, as integers.
            'amount' => Money::toMinor($payment->charged_amount),
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $customer['callback_url'],
            'metadata' => [
                'payment_id' => $payment->id,
                'description' => $customer['description'],
                'cancel_action' => $customer['callback_url'],
                'custom_fields' => [
                    ['display_name' => 'Guest', 'variable_name' => 'guest', 'value' => $customer['name']],
                ],
            ],
        ]));

        $url = $response->json('data.authorization_url');

        if (! $response->successful() || $response->json('status') !== true || ! is_string($url)) {
            throw new GatewayException('Paystack could not start the payment: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return new InitializeResult($url, $response->json('data.access_code'));
    }

    public function verify(Payment $payment, PaymentGatewaySetting $setting): VerificationResult
    {
        $response = $this->send(fn () => $this->client($payment, $setting)->get('/transaction/verify/'.rawurlencode($payment->reference)));

        if (in_array($response->status(), [400, 404], true)) {
            // Reference not known yet - the payer has not reached the payment page.
            return VerificationResult::pending($response->json('message'));
        }

        if (! $response->successful() || $response->json('status') !== true) {
            throw new GatewayException('Paystack verification failed: HTTP '.$response->status());
        }

        $data = (array) $response->json('data');

        return match ($data['status'] ?? null) {
            'success' => new VerificationResult(
                VerificationResult::SUCCESS,
                amountMinor: (int) $data['amount'],
                currency: $data['currency'] ?? null,
                transactionId: isset($data['id']) ? (string) $data['id'] : null,
                feeMinor: isset($data['fees']) ? (int) $data['fees'] : null,
                channel: $data['channel'] ?? null,
                paidAt: isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at']) : null,
                message: $data['gateway_response'] ?? null,
            ),
            'failed', 'reversed' => VerificationResult::failed($data['gateway_response'] ?? 'Payment was declined.'),
            // abandoned / ongoing / pending / processing / queued: may still complete.
            default => VerificationResult::pending($data['gateway_response'] ?? null),
        };
    }

    public function hasValidSignature(string $rawBody, array $headers, PaymentGatewaySetting $setting): bool
    {
        $signature = (string) ($headers['x-paystack-signature'] ?? '');

        if ($signature === '') {
            return false;
        }

        foreach (GatewayMode::cases() as $mode) {
            $secret = $setting->credential('secret_key', $mode);

            if ($secret && hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(array $payload): ?WebhookNotice
    {
        $event = (string) ($payload['event'] ?? '');
        $reference = $payload['data']['reference'] ?? null;

        if ($event !== 'charge.success' || ! is_string($reference)) {
            return null;
        }

        return new WebhookNotice($event.':'.($payload['data']['id'] ?? $reference), $event, $reference);
    }

    private function client(Payment $payment, PaymentGatewaySetting $setting): PendingRequest
    {
        $mode = $payment->gateway_mode ?? $setting->mode;
        $secret = $setting->credential('secret_key', $mode) ?? throw new GatewayException("Paystack {$mode->value} secret key is not configured.");

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
            throw new GatewayException('Could not reach Paystack: '.$e->getMessage(), previous: $e);
        }
    }
}
