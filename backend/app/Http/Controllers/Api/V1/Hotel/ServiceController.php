<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Audit\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stays\ServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Property;
use App\Models\Service;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hotel services price list (spec §21). */
class ServiceController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $services = Service::query()
            ->where('property_id', Property::current()->id)
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(ServiceResource::collection($services), meta: ['categories' => Service::CATEGORIES]);
    }

    public function store(ServiceRequest $request): JsonResponse
    {
        $data = $this->normalise($request->validated());

        // Soft-deleted services still hold their name (unique index), so a
        // re-added service brings the old row back instead of hitting the index.
        $existing = Service::withTrashed()->where('property_id', Property::current()->id)->where('name', $data['name'])->first();

        if ($existing && ! $existing->trashed()) {
            throw new BusinessRuleException('A service with this name already exists.', 'DUPLICATE_SERVICE', 422);
        }

        if ($existing) {
            $existing->restore();
            $existing->fill($data)->save();
            $service = $existing;
        } else {
            $service = Service::create([...$data, 'property_id' => Property::current()->id]);
        }
        $this->audit->record('services.created', $service, null, $service->only(['name', 'category', 'price']));

        return ApiResponse::created(new ServiceResource($service), 'Service added.');
    }

    public function update(ServiceRequest $request, int $service): JsonResponse
    {
        $model = $this->find($service);
        $data = $this->normalise($request->validated());

        if (isset($data['name']) && Service::withTrashed()->where('property_id', $model->property_id)->where('name', $data['name'])->whereKeyNot($model->id)->exists()) {
            throw new BusinessRuleException('A service with this name already exists.', 'DUPLICATE_SERVICE', 422);
        }

        $before = $model->only(array_keys($data));

        $model->fill($data)->save();
        [$old, $new] = $this->audit->diff($before, $model->only(array_keys($data)));

        if ($new !== []) {
            $this->audit->record('services.updated', $model, $old, $new);
        }

        return ApiResponse::success(new ServiceResource($model), 'Service saved.');
    }

    public function destroy(int $service): JsonResponse
    {
        $model = $this->find($service);
        // Soft delete: bill lines keep pointing at it.
        $model->delete();
        $this->audit->record('services.deleted', $model, $model->only(['name', 'price']), null);

        return ApiResponse::success(null, 'Service removed.');
    }

    private function find(int $id): Service
    {
        return Service::query()->where('property_id', Property::current()->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function normalise(array $data): array
    {
        if (isset($data['price'])) {
            $data['price'] = Money::toDecimal(Money::toMinor($data['price']));
        }

        return $data;
    }
}
