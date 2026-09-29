<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Audit\AuditService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AmenityResource;
use App\Models\Amenity;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AmenityController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(AmenityResource::collection(Amenity::query()->orderBy('sort_order')->orderBy('name')->get()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('amenities', 'name')],
            'icon' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $amenity = Amenity::create($data);
        $this->audit->record('amenities.created', $amenity, null, $amenity->only(['name', 'icon']));

        return ApiResponse::created(new AmenityResource($amenity), 'Amenity added.');
    }

    public function update(Request $request, Amenity $amenity): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('amenities', 'name')->ignore($amenity)],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $before = $amenity->only(array_keys($data));
        $amenity->fill($data)->save();
        [$old, $new] = $this->audit->diff($before, $amenity->only(array_keys($data)));

        if ($new !== []) {
            $this->audit->record('amenities.updated', $amenity, $old, $new);
        }

        return ApiResponse::success(new AmenityResource($amenity), 'Amenity updated.');
    }

    public function destroy(Amenity $amenity): JsonResponse
    {
        $amenity->delete();
        $this->audit->record('amenities.deleted', $amenity, $amenity->only(['name']), null);

        return ApiResponse::success(null, 'Amenity removed.');
    }
}
