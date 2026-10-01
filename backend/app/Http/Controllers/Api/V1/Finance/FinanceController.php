<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Audit\AuditService;
use App\Domain\Finance\FinanceService;
use App\Domain\Property\HotelSettings;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Controller;
use App\Models\DailyClosing;
use App\Models\Property;
use App\Models\Refund;
use App\Support\ApiResponse;
use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Finance dashboard, monthly summary, outstanding bills, daily closing and exports (P32–P34). */
class FinanceController extends Controller
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly HotelSettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->finance->summary($from, $to));
    }

    public function outstanding(): JsonResponse
    {
        return ApiResponse::success($this->finance->outstanding());
    }

    public function day(Request $request): JsonResponse
    {
        return ApiResponse::success($this->finance->day($this->date($request)));
    }

    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'cash_counted' => ['required', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['date']);
        $closing = $this->finance->close($date, $data['cash_counted'], $data['note'] ?? null, $request->user());

        return ApiResponse::success($this->finance->day($date), $closing->cash_difference === '0.00'
            ? 'Day closed. Cash matches.'
            : 'Day closed with a cash difference of '.$closing->cash_difference.'.');
    }

    public function reopen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['date']);
        $this->finance->reopen($date, $data['reason'], $request->user());

        return ApiResponse::success($this->finance->day($date), 'Day reopened.');
    }

    /** Closings in a month (for the closing calendar). */
    public function closings(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? $this->settings->today()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        $rows = DailyClosing::query()
            ->where('property_id', Property::current()->id)
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->with('closedBy')
            ->orderBy('date')
            ->get()
            ->map(fn (DailyClosing $c) => [
                'date' => $c->date->toDateString(),
                'status' => $c->status,
                'cash_expected' => $c->cash_expected,
                'cash_counted' => $c->cash_counted,
                'cash_difference' => $c->cash_difference,
                'closed_by' => $c->closedBy?->name,
                'closed_at' => $c->closed_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['month' => $month, 'closings' => $rows]);
    }

    /** P34: CSV or Excel downloads. */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['summary', 'expenses', 'refunds', 'outstanding'])],
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ]);
        $type = $data['type'];
        $format = $data['format'] ?? 'csv';
        [$from, $to] = $type === 'outstanding' ? [$this->settings->today(), $this->settings->today()] : $this->range($request);
        $suffix = $type === 'outstanding' ? $from->toDateString() : $from->toDateString().'_'.$to->toDateString();
        $local = fn ($at) => $at ? CarbonImmutable::instance($at)->setTimezone(Property::current()->timezone)->format('Y-m-d H:i') : null;

        $this->audit->record('finance.exported', null, null, null, ['type' => $type, 'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return match ($type) {
            'summary' => Spreadsheet::download($format, "finance-summary_{$suffix}", 'Daily summary',
                ['Date', 'Received', 'Refunded', 'Expenses', 'Net', 'Day closed', 'Cash difference'],
                collect($this->finance->summary($from, $to)['days'])->map(fn ($d) => [
                    $d['date'], $d['received'], $d['refunded'], $d['expenses'], $d['net'],
                    $d['closing'] ? ($d['closing']['status'] === 'CLOSED' ? 'Yes' : 'Reopened') : 'No',
                    $d['closing']['cash_difference'] ?? null,
                ]), [1, 2, 3, 4, 6]),

            'expenses' => Spreadsheet::download($format, "expenses_{$suffix}", 'Expenses',
                ['Date', 'Number', 'Category', 'Description', 'Payee', 'Method', 'Reference', 'Amount', 'Status', 'Recorded by', 'Approved/rejected by', 'Note'],
                $this->finance->expenses($from, $to)->load(['recordedBy', 'decidedBy'])->map(fn ($e) => [
                    $e->expense_date->toDateString(), $e->number, $e->category?->name, $e->description, $e->payee,
                    $e->method->label(), $e->reference, $e->amount, $e->status->value, $e->recordedBy?->name, $e->decidedBy?->name,
                    $e->status === ExpenseStatus::Void ? 'Void: '.$e->void_reason : ($e->rejection_reason ? 'Rejected: '.$e->rejection_reason : null),
                ]), [7]),

            'refunds' => Spreadsheet::download($format, "refunds_{$suffix}", 'Refunds',
                ['Completed', 'Payment reference', 'For', 'Number', 'Method', 'Amount', 'Reason', 'Completed by'],
                Refund::query()->whereIn('id', $this->finance->money($from, $to)[1]->pluck('id'))
                    ->with(['payment.payable', 'completedBy'])->orderBy('completed_at')->get()
                    ->map(fn (Refund $r) => [
                        $local($r->completed_at), $r->payment?->reference, $r->payment?->payable_type === 'bar_tab' ? 'Bar' : 'Hotel',
                        $r->payment?->payable?->number, $r->method?->value, $r->amount, $r->reason, $r->completedBy?->name,
                    ]), [5]),

            'outstanding' => Spreadsheet::download($format, "outstanding_{$suffix}", 'Outstanding',
                ['Type', 'Number', 'Guest / customer', 'Details', 'Total', 'Paid', 'Balance'],
                (function () {
                    $o = $this->finance->outstanding();
                    foreach ($o['reservations'] as $r) {
                        yield ['Hotel', $r['number'], $r['guest'], $r['status'].' '.$r['check_in'].' → '.$r['check_out'], $r['total'], $r['paid'], $r['balance']];
                    }
                    foreach ($o['bar_tabs'] as $t) {
                        yield ['Bar', $t['number'], $t['customer'], trim(($t['table'] ?? 'No table').' · '.($t['waiter'] ?? '')), $t['total'], $t['paid'], $t['balance']];
                    }
                })(), [4, 5, 6]),
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $today = CarbonImmutable::createFromFormat('!Y-m-d', $this->settings->today()->toDateString());
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['to']) : $today;
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['from']) : $to->startOfMonth();

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        if ((int) round($from->diffInDays($to)) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a range of up to one year.']);
        }

        return [$from, $to];
    }

    private function date(Request $request): CarbonImmutable
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->settings->today()->toDateString();

        return CarbonImmutable::createFromFormat('!Y-m-d', $date);
    }
}
