<?php

namespace App\Domain\Payments\Gateways;

use Carbon\CarbonImmutable;

final class VerificationResult
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    /** Not finished yet (or not found yet) - ask again later. */
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $state,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $transactionId = null,
        public readonly ?int $feeMinor = null,
        public readonly ?string $channel = null,
        public readonly ?CarbonImmutable $paidAt = null,
        public readonly ?string $message = null,
    ) {}

    public static function pending(?string $message = null): self
    {
        return new self(self::PENDING, message: $message);
    }

    public static function failed(?string $message = null): self
    {
        return new self(self::FAILED, message: $message);
    }
}
