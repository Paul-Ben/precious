<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditService;
use App\Enums\GatewayMode;
use App\Exceptions\BusinessRuleException;
use App\Models\PaymentGatewaySetting;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lets administrators configure Paystack / Flutterwave credentials without
 * touching environment variables. Secrets are encrypted at rest (APP_KEY),
 * never returned by the API and never written to the audit log.
 */
class PaymentGatewaySettingsService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Ensures a row exists for every supported gateway.
     */
    public function ensureRows(): void
    {
        foreach (GatewayRegistry::definitions() as $gateway => $definition) {
            PaymentGatewaySetting::firstOrCreate(
                ['gateway' => $gateway],
                ['display_name' => $definition['name'], 'mode' => GatewayMode::Test, 'is_enabled' => false]
            );
        }
    }

    /**
     * @param  array{
     *     display_name?: string,
     *     mode?: string,
     *     is_enabled?: bool,
     *     is_default?: bool,
     *     credentials?: array<string, array<string, ?string>>
     * }  $data  credentials shaped as ['test' => ['public_key' => ...], 'live' => [...]].
     *           Blank/omitted secret values keep the stored value; send
     *           clear_credentials: ['live.secret_key'] to remove one.
     */
    public function update(PaymentGatewaySetting $setting, array $data, User $actor): PaymentGatewaySetting
    {
        $definition = GatewayRegistry::get($setting->gateway);
        $credentials = $setting->credentials ?? [];
        $changedFields = [];

        foreach (($data['credentials'] ?? []) as $mode => $fields) {
            $modeEnum = GatewayMode::from($mode);

            foreach ($fields as $field => $value) {
                if (! isset($definition['fields'][$field])) {
                    continue;
                }

                $value = is_string($value) ? trim($value) : null;

                if ($value === null || $value === '') {
                    continue; // keep existing
                }

                $this->assertPrefix($definition['fields'][$field], $modeEnum, $field, $value);

                $key = GatewayRegistry::credentialKey($modeEnum, $field);

                if (($credentials[$key] ?? null) !== $value) {
                    $credentials[$key] = $value;
                    $changedFields[] = $key;
                }
            }
        }

        foreach (($data['clear_credentials'] ?? []) as $path) {
            [$mode, $field] = array_pad(explode('.', (string) $path, 2), 2, null);
            $key = $mode.'_'.$field;

            if (array_key_exists($key, $credentials)) {
                unset($credentials[$key]);
                $changedFields[] = $key;
            }
        }

        $mode = isset($data['mode']) ? GatewayMode::from($data['mode']) : $setting->mode;
        $enabled = (bool) ($data['is_enabled'] ?? $setting->is_enabled);
        // Disabling a gateway also removes its default flag.
        $default = $enabled && (bool) ($data['is_default'] ?? $setting->is_default);

        if ($enabled) {
            $missing = $this->missingFields($setting->gateway, $credentials, $mode);

            if ($missing !== []) {
                throw new BusinessRuleException(
                    "Cannot enable {$definition['name']} in {$mode->value} mode: missing ".implode(', ', $missing).'.',
                    'GATEWAY_INCOMPLETE',
                    422,
                    ['missing' => $missing]
                );
            }
        }

        if (($data['is_default'] ?? false) && ! $enabled) {
            throw new BusinessRuleException('Only an enabled gateway can be the default.', 'GATEWAY_DISABLED', 422);
        }

        return DB::transaction(function () use ($setting, $data, $credentials, $mode, $enabled, $default, $changedFields, $actor) {
            $before = $this->publicState($setting);

            if ($default) {
                PaymentGatewaySetting::whereKeyNot($setting->getKey())->update(['is_default' => false]);
            }

            $setting->forceFill([
                'display_name' => $data['display_name'] ?? $setting->display_name,
                'mode' => $mode,
                'is_enabled' => $enabled,
                'is_default' => $default,
                'credentials' => $credentials,
                'updated_by' => $actor->getKey(),
            ])->save();

            [$old, $new] = $this->audit->diff($before, $this->publicState($setting));

            if ($new !== [] || $changedFields !== []) {
                $this->audit->record('settings.payment_gateway_updated', $setting, $old, $new, [
                    'gateway' => $setting->gateway,
                    // Names of changed credential fields only - never values.
                    'changed_fields' => array_values(array_unique($changedFields)),
                ]);
            }

            return $setting->refresh();
        });
    }

    /**
     * Calls the gateway with the stored secret key to confirm it is valid.
     *
     * @return array{success: bool, message: string, mode: string}
     */
    public function testConnection(PaymentGatewaySetting $setting, ?GatewayMode $mode = null): array
    {
        $mode ??= $setting->mode;
        $definition = GatewayRegistry::get($setting->gateway);
        $secret = $setting->credential('secret_key', $mode);

        if (! $secret) {
            throw new BusinessRuleException(
                "No {$mode->value} secret key saved for {$definition['name']}.",
                'GATEWAY_INCOMPLETE',
                422
            );
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->timeout(15)
                ->get($definition['test_url']);

            [$success, $message] = match (true) {
                $response->successful() => [true, 'Connection successful. The secret key is valid.'],
                in_array($response->status(), [401, 403], true) => [false, 'The gateway rejected the secret key.'],
                default => [false, 'The gateway returned HTTP '.$response->status().'.'],
            };
        } catch (ConnectionException) {
            [$success, $message] = [false, 'Could not reach the gateway. Check the server\'s internet connection.'];
        }

        $setting->forceFill([
            'last_tested_at' => now(),
            'last_test_mode' => $mode->value,
            'last_test_succeeded' => $success,
            'last_test_message' => Str::limit($message, 250),
        ])->save();

        $this->audit->record('settings.payment_gateway_tested', $setting, metadata: [
            'gateway' => $setting->gateway,
            'mode' => $mode->value,
            'success' => $success,
        ]);

        return ['success' => $success, 'message' => $message, 'mode' => $mode->value];
    }

    /**
     * @return list<string> labels of required fields that are not set for the mode
     */
    public function missingFields(string $gateway, array $credentials, GatewayMode $mode): array
    {
        $missing = [];

        foreach (GatewayRegistry::get($gateway)['fields'] as $field => $meta) {
            $value = $credentials[GatewayRegistry::credentialKey($mode, $field)] ?? null;

            if ($meta['required'] && (! is_string($value) || $value === '')) {
                $missing[] = $meta['label'];
            }
        }

        return $missing;
    }

    /**
     * Masked view of credentials for API responses: public keys are shown,
     * secrets only as "set/not set" with the last 4 characters.
     *
     * @return array<string, array<string, array{set: bool, preview: ?string}>>
     */
    public function maskedCredentials(PaymentGatewaySetting $setting): array
    {
        $definition = GatewayRegistry::get($setting->gateway);
        $credentials = $setting->credentials ?? [];
        $result = [];

        foreach (GatewayMode::cases() as $mode) {
            foreach ($definition['fields'] as $field => $meta) {
                $value = $credentials[GatewayRegistry::credentialKey($mode, $field)] ?? null;
                $set = is_string($value) && $value !== '';

                $result[$mode->value][$field] = [
                    'set' => $set,
                    'preview' => ! $set ? null : ($meta['secret'] ? '••••'.Str::substr($value, -4) : $value),
                ];
            }
        }

        return $result;
    }

    private function assertPrefix(array $meta, GatewayMode $mode, string $field, string $value): void
    {
        $prefix = $meta['prefix'][$mode->value] ?? null;

        if ($prefix !== null && ! Str::startsWith($value, $prefix)) {
            throw ValidationException::withMessages([
                "credentials.{$mode->value}.{$field}" => ["{$meta['label']} for {$mode->value} mode must start with \"{$prefix}\"."],
            ]);
        }
    }

    private function publicState(PaymentGatewaySetting $setting): array
    {
        return [
            'display_name' => $setting->display_name,
            'mode' => $setting->mode?->value,
            'is_enabled' => $setting->is_enabled,
            'is_default' => $setting->is_default,
        ];
    }
}
