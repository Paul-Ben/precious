<?php

namespace App\Http\Controllers\Api\V1\Bar;

use App\Domain\Bar\TabService;
use App\Domain\Payments\PaymentService;
use App\Enums\BarTabStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ManualPaymentRequest;
use App\Http\Resources\Bar\BarOrderResource;
use App\Http\Resources\Bar\BarTabResource;
use App\Http\Resources\PaymentResource;
use App\Models\BarTab;
use App\Models\Property;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TabController extends Controller
{
    public function __construct(
        private readonly TabService $tabs,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(BarTabStatus::class)],
            'table_id' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $list = BarTab::query()
            ->where('property_id', Property::current()->id)
            ->with(['table', 'waiter'])
            ->where('status', $filters['status'] ?? BarTabStatus::Open->value)
            ->when($filters['table_id'] ?? null, fn ($q, $id) => $q->where('table_id', $id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('waiter_id', $request->user()->id))
            ->when($filters['date'] ?? null, fn ($q, $d) => $q->whereDate('opened_at', $d))
            ->orderByDesc('opened_at')
            ->paginate($filters['per_page'] ?? 50);

        $resource = BarTabResource::collection($list);
        $resource->collection->each->forStaff();

        return ApiResponse::success($resource);
    }

    public function show(string $tab): JsonResponse
    {
        return ApiResponse::success($this->resource($this->find($tab)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'table_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            // P10: optional; with an email the bill and pay link are sent.
            'customer_email' => ['nullable', 'email', 'max:190'],
        ]);

        return ApiResponse::created($this->resource($this->tabs->open($data, $request->user())), 'Bill opened.');
    }

    public function update(Request $request, string $tab): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ]);

        return ApiResponse::success($this->resource($this->tabs->updateCustomer($this->find($tab), $data)), 'Customer saved.');
    }

    public function placeOrder(Request $request, string $tab): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->tabs->placeOrder($this->find($tab), $data['items'], $data['notes'] ?? null, $request->user());

        return ApiResponse::created(new BarOrderResource($order->load(['items', 'tab.table', 'waiter'])), "Order {$order->number} sent to the bar.");
    }

    public function discount(Request $request, string $tab): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return ApiResponse::success($this->resource($this->tabs->applyDiscount($this->find($tab), $data['amount'], $data['reason'], $request->user())), 'Discount applied.');
    }

    public function pay(ManualPaymentRequest $request, string $tab): JsonResponse
    {
        $payment = $this->payments->recordManualForTab($this->find($tab), $request->validated(), $request->user());

        return ApiResponse::created(
            (new PaymentResource($payment->load(['receipt', 'recordedBy', 'refunds'])))->forStaff()->resolve($request),
            'Payment recorded. Receipt '.$payment->receipt?->number.' issued.'
        );
    }

    public function chargeToRoom(Request $request, string $tab): JsonResponse
    {
        $data = $request->validate([
            'room_number' => ['required', 'string', 'max:20'],
            'surname' => ['required', 'string', 'max:100'],
        ]);

        $model = $this->tabs->chargeToRoom($this->find($tab), $data['room_number'], $data['surname'], $request->user());

        return ApiResponse::success($this->resource($model), "Charged to room {$data['room_number']}. Ask the guest to sign the slip.");
    }

    public function close(Request $request, string $tab): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;
        $model = $this->tabs->close($this->find($tab), $request->user(), $note);

        return ApiResponse::success($this->resource($model), $model->status === BarTabStatus::Cancelled ? 'Empty bill cancelled.' : 'Bill closed.');
    }

    public function email(Request $request, string $tab): JsonResponse
    {
        $email = $request->validate(['email' => ['nullable', 'email', 'max:190']])['email'] ?? null;
        $model = $this->find($tab);

        return $this->tabs->emailBill($model, final: ! $model->isOpen(), to: $email)
            ? ApiResponse::success(null, 'Bill emailed.')
            : ApiResponse::error('No email address to send to.', 422, 'EMAIL_FAILED');
    }

    private function find(string $id): BarTab
    {
        abort_unless(Str::isUuid($id), 404);

        return BarTab::query()->where('property_id', Property::current()->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function resource(BarTab $tab): array
    {
        $tab->refresh()->load(['table', 'waiter', 'orders.items', 'orders.waiter', 'payments.receipt', 'payments.recordedBy', 'payments.refunds', 'stay.room']);

        return (new BarTabResource($tab))->forStaff()->resolve(request());
    }
}
