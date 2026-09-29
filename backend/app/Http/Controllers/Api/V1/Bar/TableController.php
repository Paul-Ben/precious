<?php

namespace App\Http\Controllers\Api\V1\Bar;

use App\Domain\Audit\AuditService;
use App\Enums\BarTableStatus;
use App\Enums\BarTabStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bar\BarTableResource;
use App\Models\BarTable;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $tables = BarTable::query()
            ->where('property_id', Property::current()->id)
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->with(['tabs' => fn ($q) => $q->where('status', BarTabStatus::Open->value)->orderBy('opened_at')])
            ->orderBy('sort_order')
            ->orderByRaw('LENGTH(name), name')
            ->get();

        return ApiResponse::success(BarTableResource::collection($tables));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $table = BarTable::create([...$data, 'property_id' => Property::current()->id]);
        $this->audit->record('bar.table_created', $table, null, ['name' => $table->name]);

        return ApiResponse::created(new BarTableResource($table), 'Table added.');
    }

    public function update(Request $request, int $table): JsonResponse
    {
        $model = $this->find($table);
        $model->fill($this->validated($request, $model))->save();

        return ApiResponse::success(new BarTableResource($model), 'Table saved.');
    }

    public function status(Request $request, int $table): JsonResponse
    {
        $model = $this->find($table);
        $status = BarTableStatus::from($request->validate(['status' => ['required', Rule::enum(BarTableStatus::class)]])['status']);

        $hasOpen = $model->tabs()->where('status', BarTabStatus::Open->value)->exists();

        if ($hasOpen && $status !== BarTableStatus::Occupied) {
            throw new BusinessRuleException('This table has an open bill. Close it first.', 'TABLE_IN_USE', 409);
        }

        $old = $model->status->value;
        $model->forceFill(['status' => $status])->save();
        $this->audit->record('bar.table_status', $model, ['status' => $old], ['status' => $status->value]);

        return ApiResponse::success(new BarTableResource($model), 'Table updated.');
    }

    public function destroy(int $table): JsonResponse
    {
        $model = $this->find($table);

        if ($model->tabs()->exists()) {
            $model->forceFill(['is_active' => false])->save();

            return ApiResponse::success(null, 'Table has history, so it was switched off instead of deleted.');
        }

        $model->delete();

        return ApiResponse::success(null, 'Table removed.');
    }

    private function find(int $id): BarTable
    {
        return BarTable::query()->where('property_id', Property::current()->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BarTable $model = null): array
    {
        $req = $model ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:40', Rule::unique('bar_tables')->where('property_id', Property::current()->id)->ignore($model?->id)],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'area' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
