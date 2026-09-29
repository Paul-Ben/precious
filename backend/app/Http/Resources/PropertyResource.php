<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    /** @var array<string, mixed>|null */
    private ?array $policies = null;

    /**
     * @param  array<string, mixed>  $policies
     */
    public function withPolicies(array $policies): static
    {
        $this->policies = $policies;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'description' => $this->description,
            'policies' => $this->when($this->policies !== null, fn () => $this->policies),
        ];
    }
}
