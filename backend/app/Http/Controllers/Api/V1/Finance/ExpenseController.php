<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Audit\AuditService;
use App\Domain\Finance\ExpenseService;
use App\Domain\Property\HotelSettings;
use App\Enums\ExpenseMethod;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Property;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Expenses and their categories (P29–P31). */
class ExpenseController extends Controller
{
    private const RELATIONS = ['category', 'recordedBy', 'decidedBy', 'voidedBy'];

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly HotelSettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(ExpenseStatus::class)],
            'category_id' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::enum(ExpenseMethod::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filtered($f);
        $totals = (clone $query)->reorder()->where('status', ExpenseStatus::Approved->value)->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n')->first();

        $page = $query->with(self::RELATIONS)->orderByDesc('expense_date')->orderByDesc('created_at')->paginate($f['per_page'] ?? 25)->withQueryString();

        return ApiResponse::success(ExpenseResource::collection($page), meta: [
            'approved_total' => Money::toDecimal(Money::toMinor((string) $totals->total)),
            'approved_count' => (int) $totals->n,
            'pending_count' => Expense::query()->where('property_id', Property::current()->id)->where('status', ExpenseStatus::Pending->value)->count(),
            'approval_limit' => $this->expenses->approvalLimit(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(),
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $expense = $this->expenses->create($data, $request->user(), $request->file('receipt'));
        $message = $expense->status === ExpenseStatus::Pending
            ? 'Expense recorded. It is above '.Money::format(Money::toMinor($this->expenses->approvalLimit())).' and waits for a manager\'s approval.'
            : 'Expense recorded.';

        return ApiResponse::created(new ExpenseResource($expense->load(self::RELATIONS)), $message);
    }

    public function show(Expense $expense): JsonResponse
    {
        return ApiResponse::success(new ExpenseResource($expense->load(self::RELATIONS)));
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));

        return ApiResponse::success(new ExpenseResource($this->expenses->update($expense, $data, $request->user())->load(self::RELATIONS)), 'Expense updated.');
    }

    public function approve(Request $request, Expense $expense): JsonResponse
    {
        return ApiResponse::success(new ExpenseResource($this->expenses->approve($expense, $request->user())->load(self::RELATIONS)), 'Expense approved.');
    }

    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return ApiResponse::success(new ExpenseResource($this->expenses->reject($expense, $reason, $request->user())->load(self::RELATIONS)), 'Expense rejected.');
    }

    public function void(Request $request, Expense $expense): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];

        return ApiResponse::success(new ExpenseResource($this->expenses->void($expense, $reason, $request->user())->load(self::RELATIONS)), 'Expense voided.');
    }

    public function storeReceipt(Request $request, Expense $expense): JsonResponse
    {
        $request->validate(['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192']]);

        return ApiResponse::success(new ExpenseResource($this->expenses->storeReceipt($expense, $request->file('receipt'))->load(self::RELATIONS)), 'Receipt attached.');
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        return $this->expenses->receipt($expense);
    }

    // ------------------------------------------------------------ categories

    public function categories(): JsonResponse
    {
        return ApiResponse::success(
            ExpenseCategory::query()->where('property_id', Property::current()->id)->orderBy('name')->get(['id', 'name', 'is_active'])
        );
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('expense_categories', 'name')->where('property_id', Property::current()->id)]]);
        $category = ExpenseCategory::query()->create([...$data, 'property_id' => Property::current()->id]);
        $this->audit->record('expense_categories.created', $category, null, $data);

        return ApiResponse::created($category->only(['id', 'name', 'is_active']), 'Category added.');
    }

    public function updateCategory(Request $request, ExpenseCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('expense_categories', 'name')->where('property_id', Property::current()->id)->ignore($category->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $before = $category->only(array_keys($data));
        $category->fill($data)->save();
        [$old, $new] = $this->audit->diff($before, $category->only(array_keys($data)));
        $this->audit->record('expense_categories.updated', $category, $old, $new);

        return ApiResponse::success($category->only(['id', 'name', 'is_active']), 'Category saved.');
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $today = $this->settings->today()->toDateString();

        return [
            'category_id' => [$req, 'integer', Rule::exists('expense_categories', 'id')->where('property_id', Property::current()->id)->where('is_active', true)],
            'expense_date' => [$req, 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'description' => [$req, 'string', 'min:3', 'max:255'],
            'payee' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount' => [$req, 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'method' => [$req, Rule::enum(ExpenseMethod::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /** @param array<string, mixed> $f */
    private function filtered(array $f): Builder
    {
        return Expense::query()
            ->where('property_id', Property::current()->id)
            ->when($f['from'] ?? null, fn (Builder $q, $d) => $q->where('expense_date', '>=', $d))
            ->when($f['to'] ?? null, fn (Builder $q, $d) => $q->where('expense_date', '<=', $d))
            ->when($f['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($f['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($f['method'] ?? null, fn (Builder $q, $m) => $q->where('method', $m))
            ->when($f['search'] ?? null, function (Builder $q, string $s) {
                $term = '%'.mb_strtolower($s).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(payee, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(reference, \'\')) LIKE ?', [$term]));
            });
    }
}
