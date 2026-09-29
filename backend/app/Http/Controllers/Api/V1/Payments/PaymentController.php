<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Domain\Payments\PaymentService;
use App\Domain\Payments\ReceiptService;
use App\Domain\Property\HotelSettings;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\ManualPaymentRequest;
use App\Http\Requests\Payments\ResolveAttentionRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ReceiptResource;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Receipt;
use App\Models\Refund;
use App\Models\Reservation;
use App\Support\ApiResponse;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly ReceiptService $receipts,
        private readonly HotelSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'attention' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $tz = Property::current()->timezone;

        $payments = Payment::query()
            ->where('property_id', Property::current()->id)
            ->with(['receipt', 'recordedBy', 'guest', 'payable', 'refunds'])
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['method'] ?? null, fn (Builder $q, $v) => $q->where('method', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', CarbonImmutable::parse($v, $tz)->startOfDay()->setTimezone(config('app.timezone'))))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<', CarbonImmutable::parse($v, $tz)->addDay()->startOfDay()->setTimezone(config('app.timezone'))))
            ->when($request->boolean('attention'), fn (Builder $q) => $q->where('needs_attention', true))
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->where('reference', 'ilike', $like)
                    ->orWhere('external_reference', 'ilike', $like)
                    ->orWhere('payer_email', 'ilike', $like)
                    ->orWhereIn('payable_id', Reservation::query()->select('id')->where('number', 'ilike', $like))
                    ->orWhereHas('guest', fn (Builder $g) => $g->whereRaw("(first_name || ' ' || last_name) ilike ?", [$like])));
            })
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);

        $resource = PaymentResource::collection($payments);
        $resource->collection->each->forStaff();

        return ApiResponse::success($resource);
    }

    public function show(string $payment): JsonResponse
    {
        return ApiResponse::success($this->resource($this->find($payment)));
    }

    public function forReservation(string $reservation): JsonResponse
    {
        $model = Reservation::query()->findOrFail($reservation);

        return ApiResponse::success(
            $this->payments->forReservation($model)->map(fn (Payment $p) => (new PaymentResource($p->setRelation('payable', $model)))->forStaff()->resolve(request()))
        );
    }

    public function store(ManualPaymentRequest $request, string $reservation): JsonResponse
    {
        $payment = $this->payments->recordManual(Reservation::query()->findOrFail($reservation), $request->validated(), $request->user());

        return ApiResponse::created($this->resource($payment), 'Payment recorded. Receipt '.$payment->receipt?->number.' issued.');
    }

    /** Re-checks a pending online payment with the gateway. */
    public function verify(string $payment): JsonResponse
    {
        $model = $this->payments->verify($this->find($payment));

        return ApiResponse::success($this->resource($model), match ($model->status) {
            TransactionStatus::Successful => 'Payment confirmed by the gateway.',
            TransactionStatus::Failed => 'The gateway reports this payment failed.',
            default => 'The gateway has not completed this payment yet.',
        });
    }

    public function resolve(ResolveAttentionRequest $request, string $payment): JsonResponse
    {
        $model = $this->payments->resolveAttention($this->find($payment), $request->validated('note'), $request->user());

        return ApiResponse::success($this->resource($model), 'Marked as resolved.');
    }

    public function receipt(string $number): JsonResponse
    {
        return ApiResponse::success(new ReceiptResource(Receipt::query()->where('number', $number)->firstOrFail()));
    }

    public function emailReceipt(Request $request, string $number): JsonResponse
    {
        $email = $request->validate(['email' => ['nullable', 'email', 'max:190']])['email'] ?? null;
        $receipt = Receipt::query()->where('number', $number)->firstOrFail();

        return $this->receipts->email($receipt, $email)
            ? ApiResponse::success(new ReceiptResource($receipt->refresh()), 'Receipt emailed.')
            : ApiResponse::error('The receipt could not be emailed. Check the email address and try again.', 422, 'EMAIL_FAILED');
    }

    /**
     * Cash-up for one day (hotel time): money received per method, refunds
     * paid out, and the net.
     */
    public function summary(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->settings->today()->toDateString();
        $tz = Property::current()->timezone;
        // Stored timestamps are in the app time zone; bindings are formatted in
        // the Carbon's own zone, so convert the hotel-day bounds before querying.
        $start = CarbonImmutable::parse($date, $tz)->startOfDay();
        $end = $start->addDay()->setTimezone(config('app.timezone'));
        $start = $start->setTimezone(config('app.timezone'));

        $received = Payment::query()
            ->where('property_id', Property::current()->id)
            ->where('status', TransactionStatus::Successful->value)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->get();

        $refunds = Refund::query()
            ->where('status', RefundStatus::Completed->value)
            ->where('completed_at', '>=', $start)->where('completed_at', '<', $end)
            ->whereHas('payment', fn (Builder $q) => $q->where('property_id', Property::current()->id))
            ->get();

        $byMethod = collect(PaymentMethod::cases())->map(function (PaymentMethod $method) use ($received) {
            $rows = $received->where('method', $method);

            return [
                'method' => $method->value,
                'label' => $method->label(),
                'count' => $rows->count(),
                'amount' => Money::toDecimal($rows->sum(fn (Payment $p) => Money::toMinor($p->amount))),
                'customer_fees' => Money::toDecimal($rows->sum(fn (Payment $p) => Money::toMinor($p->customer_fee))),
            ];
        })->values();

        $receivedMinor = $received->sum(fn (Payment $p) => Money::toMinor($p->amount));
        $refundedMinor = $refunds->sum(fn (Refund $r) => Money::toMinor($r->amount));

        return ApiResponse::success([
            'date' => $date,
            'by_method' => $byMethod,
            'received' => Money::toDecimal($receivedMinor),
            'refunded' => Money::toDecimal($refundedMinor),
            'refunds_count' => $refunds->count(),
            'net' => Money::toDecimal($receivedMinor - $refundedMinor),
            // Physical cash the desk should hold for the day.
            'cash_in_hand' => Money::toDecimal(
                $received->where('method', PaymentMethod::Cash)->sum(fn (Payment $p) => Money::toMinor($p->amount))
                - $refunds->filter(fn (Refund $r) => $r->method?->value === 'CASH')->sum(fn (Refund $r) => Money::toMinor($r->amount))
            ),
            'needs_attention' => Payment::query()->where('property_id', Property::current()->id)->where('needs_attention', true)->count(),
        ]);
    }

    private function find(string $id): Payment
    {
        return Payment::query()->where('property_id', Property::current()->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function resource(Payment $payment): array
    {
        $payment->load(['receipt', 'recordedBy', 'guest', 'payable', 'refunds']);

        return (new PaymentResource($payment))->forStaff()->resolve(request());
    }
}
