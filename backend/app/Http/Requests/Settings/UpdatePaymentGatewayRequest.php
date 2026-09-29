<?php

namespace App\Http\Requests\Settings;

use App\Domain\Payments\GatewayRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: permission:settings.payment_gateways.manage
    }

    public function rules(): array
    {
        $gateway = (string) $this->route('gateway');
        $fields = GatewayRegistry::exists($gateway) ? array_keys(GatewayRegistry::get($gateway)['fields']) : [];

        $rules = [
            'display_name' => ['sometimes', 'required', 'string', 'max:100'],
            'mode' => ['sometimes', 'required', Rule::in(['test', 'live'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array:test,live'],
            'credentials.test' => ['sometimes', 'array:'.implode(',', $fields)],
            'credentials.live' => ['sometimes', 'array:'.implode(',', $fields)],
            'clear_credentials' => ['sometimes', 'array'],
            'clear_credentials.*' => ['string', Rule::in(collect(['test', 'live'])->crossJoin($fields)->map(fn ($p) => implode('.', $p))->all())],
        ];

        $money = ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'];
        $rules['fees'] = ['sometimes', 'nullable', 'array:percent,flat,flat_waived_below,cap'];
        $rules['fees.percent'] = ['required_with:fees', 'string', 'regex:/^\d{1,2}(\.\d{1,4})?$/'];
        $rules['fees.flat'] = $money;
        $rules['fees.flat_waived_below'] = $money;
        $rules['fees.cap'] = $money;

        foreach (['test', 'live'] as $mode) {
            foreach ($fields as $field) {
                $rules["credentials.$mode.$field"] = ['nullable', 'string', 'max:255'];
            }
        }

        return $rules;
    }
}
