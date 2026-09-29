<?php

namespace App\Domain\Property;

use App\Domain\Audit\AuditService;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class PropertyService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Property $property, array $data): Property
    {
        return DB::transaction(function () use ($property, $data) {
            $before = $property->only(array_keys($data));
            $property->fill($data)->save();
            [$old, $new] = $this->audit->diff($before, $property->only(array_keys($data)));

            if ($new !== []) {
                $this->audit->record('property.updated', $property, $old, $new);
            }

            Property::forgetCurrent();

            return $property->refresh();
        });
    }
}
