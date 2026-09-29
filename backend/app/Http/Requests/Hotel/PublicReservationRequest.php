<?php

namespace App\Http\Requests\Hotel;

class PublicReservationRequest extends QuoteRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('guest'))) {
            $guest = $this->input('guest');
            $guest['email'] = mb_strtolower(trim((string) ($guest['email'] ?? '')));
            $this->merge(['guest' => $guest]);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'guest' => ['required', 'array'],
            'guest.first_name' => ['required', 'string', 'max:80'],
            'guest.last_name' => ['required', 'string', 'max:80'],
            'guest.email' => ['required', 'email:rfc', 'max:190'],
            'guest.phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'additional_guests' => ['sometimes', 'array', 'max:10'],
            'additional_guests.*.first_name' => ['required', 'string', 'max:80'],
            'additional_guests.*.last_name' => ['required', 'string', 'max:80'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'guest.phone.regex' => 'Enter a valid phone number, e.g. +2348012345678.',
            'accept_terms.accepted' => 'Please accept the booking and cancellation terms.',
        ];
    }
}
