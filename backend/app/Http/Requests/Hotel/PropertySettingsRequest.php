<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class PropertySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $time = ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $percent = ['sometimes', 'string', 'regex:/^\d{1,2}(\.\d{1,2})?$|^100(\.0{1,2})?$/'];

        return [
            'property' => ['sometimes', 'array'],
            'property.name' => ['sometimes', 'string', 'max:150'],
            'property.legal_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'property.email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'property.phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'property.address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'property.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'property.state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'property.description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'policies' => ['sometimes', 'array'],
            'policies.deposit_percent' => $percent,
            'policies.hold_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'policies.staff_hold_max_minutes' => ['sometimes', 'integer', 'min:5', 'max:10080'],
            'policies.vat_percent' => $percent,
            'policies.vat_on_accommodation' => ['sometimes', 'boolean'],
            'policies.service_charge_percent' => $percent,
            'policies.accommodation_service_charge_percent' => $percent,
            'policies.check_in_time' => $time,
            'policies.check_out_time' => $time,
            'policies.late_checkout_half_rate_until' => $time,
            'policies.free_cancellation_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'policies.no_show_time' => $time,
            'policies.max_nights' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'policies.max_rooms_per_booking' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'policies.booking_window_days' => ['sometimes', 'integer', 'min:1', 'max:730'],
            'policies.pass_gateway_fees_to_customer' => ['sometimes', 'boolean'],
            'policies.require_id_at_check_in' => ['sometimes', 'boolean'],
            'policies.refund_second_approval_above' => ['sometimes', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
        ];
    }
}
