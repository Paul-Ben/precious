<?php

namespace App\Domain\Payments\Gateways;

final class WebhookNotice
{
    public function __construct(
        /** Unique per gateway event - used to process each delivery once. */
        public readonly string $eventKey,
        public readonly string $eventType,
        /** Our payment reference (Paystack reference / Flutterwave tx_ref). */
        public readonly string $reference,
    ) {}
}
