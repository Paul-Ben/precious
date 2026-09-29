<?php

namespace App\Domain\Payments\Gateways;

final class InitializeResult
{
    public function __construct(
        public readonly string $authorizationUrl,
        public readonly ?string $gatewayReference = null,
    ) {}
}
