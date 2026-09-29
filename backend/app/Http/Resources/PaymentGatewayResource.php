<?php

namespace App\Http\Resources;

use App\Domain\Payments\FeeSchedule;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentGatewaySettingsService;
use App\Enums\GatewayMode;
use App\Models\PaymentGatewaySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes secret values - only whether they are set and the last 4 chars.
 *
 * @mixin PaymentGatewaySetting
 */
class PaymentGatewayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(PaymentGatewaySettingsService::class);
        $definition = GatewayRegistry::get($this->gateway);
        $credentials = $this->credentials ?? [];

        return [
            'gateway' => $this->gateway,
            'name' => $definition['name'],
            'display_name' => $this->display_name,
            'is_enabled' => $this->is_enabled,
            'is_default' => $this->is_default,
            'mode' => $this->mode->value,
            'docs_url' => $definition['docs_url'],
            'dashboard_url' => $definition['dashboard_url'],
            'webhook_url' => url('/api/v1/webhooks/payments/'.$this->gateway),
            'fields' => collect($definition['fields'])->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'secret' => $meta['secret'],
                'required' => $meta['required'],
                'help' => $meta['help'],
            ])->values(),
            'credentials' => $service->maskedCredentials($this->resource),
            'missing' => [
                'test' => $service->missingFields($this->gateway, $credentials, GatewayMode::Test),
                'live' => $service->missingFields($this->gateway, $credentials, GatewayMode::Live),
            ],
            // Processing fee used to work out what the payer is charged on top.
            'fees' => FeeSchedule::valuesFor($this->resource),
            'fee_defaults' => config('payments.default_fees.'.$this->gateway),
            'last_test' => $this->last_tested_at ? [
                'tested_at' => $this->last_tested_at->toIso8601String(),
                'mode' => $this->last_test_mode,
                'succeeded' => $this->last_test_succeeded,
                'message' => $this->last_test_message,
            ] : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
