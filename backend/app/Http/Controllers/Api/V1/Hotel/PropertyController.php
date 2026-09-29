<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Property\HotelSettings;
use App\Domain\Property\PropertyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\PropertySettingsRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Department;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function __construct(
        private readonly HotelSettings $settings,
        private readonly PropertyService $properties,
    ) {}

    public function show(): JsonResponse
    {
        $property = Property::current();

        return ApiResponse::success([
            ...(new PropertyResource($property))->withPolicies($this->settings->all())->resolve(request()),
            'departments' => Department::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name', 'code', 'is_active']),
        ]);
    }

    public function update(PropertySettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $property = Property::current();

        DB::transaction(function () use ($data, $property, $request) {
            if (! empty($data['property'])) {
                $this->properties->update($property, $data['property']);
            }

            if (! empty($data['policies'])) {
                $this->settings->update($data['policies'], $request->user(), $property);
            }
        });

        return ApiResponse::success(
            (new PropertyResource(Property::current()))->withPolicies($this->settings->all()),
            'Property settings saved.'
        );
    }
}
