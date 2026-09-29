<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Domain\Payments\RefundService;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\CompleteRefundRequest;
use App\Http\Requests\Payments\RefundDecisionRequest;
use App\Http\Requests\Payments\RequestRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Refund;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    private const RELATIONS = ['payment.payable', 'requestedBy', 'approvedBy', 'rejectedBy', 'completedBy'];

    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $list = Refund::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('property_id', Property::current()->id))
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->orderByRaw("CASE status WHEN 'REQUESTED' THEN 0 WHEN 'APPROVED' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(RefundResource::collection($list));
    }

    public function store(RequestRefundRequest $request, string $payment): JsonResponse
    {
        $model = Payment::query()->where('property_id', Property::current()->id)->findOrFail($payment);
        $refund = $this->refunds->request($model, $request->validated('amount'), $request->validated('reason'), $request->user());

        return ApiResponse::created(
            new RefundResource($refund->load(self::RELATIONS)),
            $refund->requires_second_approval
                ? "Refund {$refund->number} requested. Another staff member with refund permission must approve it."
                : "Refund {$refund->number} approved. Record it as completed once the money has been sent."
        );
    }

    public function approve(RefundDecisionRequest $request, string $refund): JsonResponse
    {
        $model = $this->refunds->approve($this->find($refund), $request->user(), $request->validated('note'));

        return ApiResponse::success(new RefundResource($model->load(self::RELATIONS)), 'Refund approved.');
    }

    public function reject(RefundDecisionRequest $request, string $refund): JsonResponse
    {
        $model = $this->refunds->reject($this->find($refund), $request->user(), $request->validated('note'));

        return ApiResponse::success(new RefundResource($model->load(self::RELATIONS)), 'Refund rejected.');
    }

    public function complete(CompleteRefundRequest $request, string $refund): JsonResponse
    {
        $model = $this->refunds->complete(
            $this->find($refund),
            $request->user(),
            RefundMethod::from($request->validated('method')),
            $request->validated('external_reference'),
        );

        return ApiResponse::success(new RefundResource($model->load(self::RELATIONS)), 'Refund recorded as completed.');
    }

    private function find(string $id): Refund
    {
        return Refund::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('property_id', Property::current()->id))
            ->findOrFail($id);
    }
}
