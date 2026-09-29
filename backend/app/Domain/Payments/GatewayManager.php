<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Gateways\FlutterwaveGateway;
use App\Domain\Payments\Gateways\PaymentGateway;
use App\Domain\Payments\Gateways\PaystackGateway;
use App\Exceptions\BusinessRuleException;
use App\Models\PaymentGatewaySetting;
use Illuminate\Support\Collection;

/**
 * Resolves gateway drivers and the administrator's gateway settings.
 */
class GatewayManager
{
    public function driver(string $gateway): PaymentGateway
    {
        return match ($gateway) {
            GatewayRegistry::PAYSTACK => app(PaystackGateway::class),
            GatewayRegistry::FLUTTERWAVE => app(FlutterwaveGateway::class),
            default => throw new \InvalidArgumentException("Unknown gateway [{$gateway}]."),
        };
    }

    public function setting(string $gateway): ?PaymentGatewaySetting
    {
        return GatewayRegistry::exists($gateway)
            ? PaymentGatewaySetting::query()->where('gateway', $gateway)->first()
            : null;
    }

    /**
     * Enabled gateways, default first.
     *
     * @return Collection<int, PaymentGatewaySetting>
     */
    public function enabled(): Collection
    {
        return PaymentGatewaySetting::query()
            ->where('is_enabled', true)
            ->whereIn('gateway', array_keys(GatewayRegistry::definitions()))
            ->orderByDesc('is_default')
            ->orderBy('gateway')
            ->get();
    }

    /** The requested gateway if enabled, else the default one. */
    public function choose(?string $gateway): PaymentGatewaySetting
    {
        $enabled = $this->enabled();

        if ($enabled->isEmpty()) {
            throw new BusinessRuleException(
                'Online payment is not available right now. Please contact the hotel to pay.',
                'NO_PAYMENT_GATEWAY',
                422
            );
        }

        if ($gateway === null) {
            return $enabled->first();
        }

        return $enabled->firstWhere('gateway', $gateway) ?? throw new BusinessRuleException(
            'That payment option is not available.',
            'GATEWAY_DISABLED',
            422
        );
    }
}
