<?php

namespace App\Http\Controllers\Api\V1\Bar;

use App\Domain\Bar\TabService;
use App\Enums\BarOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bar\BarOrderResource;
use App\Models\BarOrder;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bartender queue (spec §25): NEW → ACCEPTED → PREPARING → READY. */
class OrderController extends Controller
{
    public function __construct(private readonly TabService $tabs) {}

    public function queue(Request $request): JsonResponse
    {
        $orders = BarOrder::query()
            ->whereHas('tab', fn ($q) => $q->where('property_id', Property::current()->id))
            ->whereIn('status', [BarOrderStatus::Placed->value, BarOrderStatus::Accepted->value, BarOrderStatus::Preparing->value, BarOrderStatus::Ready->value])
            ->when($request->boolean('mine'), fn ($q) => $q->where('waiter_id', $request->user()->id))
            ->with(['items', 'tab.table', 'waiter'])
            ->orderBy('placed_at')
            ->limit(200)
            ->get();

        return ApiResponse::success(BarOrderResource::collection($orders), meta: ['server_time' => now()->toIso8601String()]);
    }

    public function advance(Request $request, string $order): JsonResponse
    {
        $to = BarOrderStatus::from($request->validate([
            'status' => ['required', Rule::in(['ACCEPTED', 'PREPARING', 'READY', 'DELIVERED'])],
        ])['status']);

        // Bartenders prepare; waiters deliver.
        $permission = $to === BarOrderStatus::Delivered ? 'bar.orders.deliver' : 'bar.orders.prepare';

        if (! $request->user()->can($permission)) {
            return ApiResponse::error('You do not have permission to perform this action.', 403, 'FORBIDDEN');
        }

        $model = $this->tabs->advance($this->find($order), $to, $request->user());

        return ApiResponse::success(new BarOrderResource($model->load(['items', 'tab.table', 'waiter'])), 'Order '.strtolower($to->value).'.');
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $model = $this->tabs->cancelOrder($this->find($order), $reason, $request->user(), $request->user()->can('bar.orders.cancel'));

        return ApiResponse::success(new BarOrderResource($model->load(['items', 'tab.table', 'waiter'])), 'Order cancelled.');
    }

    private function find(string $id): BarOrder
    {
        return BarOrder::query()->whereHas('tab', fn ($q) => $q->where('property_id', Property::current()->id))->findOrFail($id);
    }
}
