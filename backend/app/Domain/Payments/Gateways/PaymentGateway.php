<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentGatewaySetting;

/**
 * One online payment provider. Implementations talk HTTP only; they never
 * change our data - PaymentService decides what a result means.
 */
interface PaymentGateway
{
    /**
     * Starts a hosted checkout and returns the page the payer is sent to.
     *
     * @param  array{email: string, name: string, phone: ?string, description: string, callback_url: string}  $customer
     */
    public function initialize(Payment $payment, PaymentGatewaySetting $setting, array $customer): InitializeResult;

    /** Asks the gateway for the authoritative status of our reference. */
    public function verify(Payment $payment, PaymentGatewaySetting $setting): VerificationResult;

    /**
     * Checks the webhook came from the gateway. Tries the secrets of both modes
     * because a test-mode payment can still be in flight after switching to live.
     *
     * @param  array<string, string|null>  $headers  lower-cased header names
     */
    public function hasValidSignature(string $rawBody, array $headers, PaymentGatewaySetting $setting): bool;

    /**
     * Extracts what we need from a webhook payload, or null for events we ignore.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): ?WebhookNotice;
}
