<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Domain\Reports\ReportService;
use App\Enums\BarTabStatus;
use App\Enums\ExpenseMethod;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BarTab;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finance figures (P32–P34): a cash view of the business.
 *
 * - Revenue = money actually received (successful payments, excluding the gateway
 *   fees guests pay on top) minus completed refunds.
 * - Expenses = approved expenses by expense date.
 * - Net position = revenue − expenses.
 * - Daily closing compares the cash the system expects with the cash counted.
 */
class FinanceService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly HotelSettings $settings,
        private readonly AuditService $audit,
        private readonly DayLock $days,
    ) {}

    /**
     * Dashboard / monthly summary for a date range (hotel calendar days).
     *
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$payments, $refunds] = $this->money($from, $to);
        $expenses = $this->expenses($from, $to);
        $approved = $expenses->where('status', ExpenseStatus::Approved);
        $report = $this->reports->summary($from, $to);

        $received = $this->sum($payments, 'amount');
        $refunded = $this->sum($refunds, 'amount');
        $spent = $this->sum($approved, 'amount');
        $pending = $expenses->where('status', ExpenseStatus::Pending);
        $closings = $this->closings($from, $to);

        $byDay = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $in = $this->sum($payments->filter(fn (Payment $p) => $this->localDate($p->paid_at) === $key), 'amount');
            $out = $this->sum($refunds->filter(fn (Refund $r) => $this->localDate($r->completed_at) === $key), 'amount');
            $exp = $this->sum($approved->filter(fn (Expense $e) => $e->expense_date->toDateString() === $key), 'amount');
            $byDay[] = [
                'date' => $key,
                'received' => Money::toDecimal($in),
                'refunded' => Money::toDecimal($out),
                'expenses' => Money::toDecimal($exp),
                'net' => Money::toDecimal($in - $out - $exp),
                'closing' => $closings[$key] ?? null,
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => [
                'received' => Money::toDecimal($received),
                'refunded' => Money::toDecimal($refunded),
                'net' => Money::toDecimal($received - $refunded),
                'hotel' => Money::toDecimal($this->sum($payments->where('payable_type', 'reservation'), 'amount')),
                'bar' => Money::toDecimal($this->sum($payments->where('payable_type', 'bar_tab'), 'amount')),
                'by_method' => $this->byMethod($payments),
                // What was earned in the range (billed), for the hotel rooms / services / bar split.
                'billed' => [
                    'rooms' => $report['hotel']['room_revenue'],
                    'services' => Money::toDecimal(collect($report['hotel']['extras'])->sum(fn ($e) => Money::toMinor($e['total']))),
                    'bar' => $report['bar']['total'],
                ],
            ],
            'expenses' => [
                'total' => Money::toDecimal($spent),
                'by_category' => $approved->groupBy(fn (Expense $e) => $e->category?->name ?? '—')
                    ->map(fn (Collection $rows, string $name) => ['category' => $name, 'count' => $rows->count(), 'amount' => Money::toDecimal($this->sum($rows, 'amount'))])
                    ->sortByDesc(fn ($r) => Money::toMinor($r['amount']))->values()->all(),
                'pending_count' => $pending->count(),
                'pending_amount' => Money::toDecimal($this->sum($pending, 'amount')),
            ],
            'net_position' => Money::toDecimal($received - $refunded - $spent),
            'outstanding' => $this->outstandingTotals(),
            'days' => $byDay,
        ];
    }

    /**
     * Guests and bar customers who owe money now.
     *
     * @return array{reservations: list<array<string, mixed>>, bar_tabs: list<array<string, mixed>>, total: string}
     */
    public function outstanding(): array
    {
        $reservations = Reservation::query()
            ->where('property_id', Property::current()->id)
            ->whereIn('status', [ReservationStatus::CheckedIn->value, ReservationStatus::CheckedOut->value])
            ->whereRaw('(total + COALESCE(charges_total, 0)) > amount_paid')
            ->with('guest')
            ->orderBy('check_out')
            ->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'guest' => $r->guest?->fullName(),
                'status' => $r->status->value,
                'check_in' => $r->check_in->toDateString(),
                'check_out' => $r->check_out->toDateString(),
                'total' => Money::toDecimal($r->grandTotalMinor()),
                'paid' => $r->amount_paid,
                'balance' => Money::toDecimal($r->balanceMinor()),
            ]);

        $tabs = BarTab::query()
            ->where('property_id', Property::current()->id)
            ->where('status', BarTabStatus::Open->value)
            ->whereColumn('total', '>', 'amount_paid')
            ->with(['table', 'waiter'])
            ->orderBy('opened_at')
            ->get()
            ->map(fn (BarTab $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'table' => $t->table?->name,
                'customer' => $t->customer_name,
                'waiter' => $t->waiter?->name,
                'opened_at' => $t->opened_at?->toIso8601String(),
                'total' => $t->total,
                'paid' => $t->amount_paid,
                'balance' => Money::toDecimal($t->balanceMinor()),
            ]);

        $total = $reservations->sum(fn ($r) => Money::toMinor($r['balance'])) + $tabs->sum(fn ($t) => Money::toMinor($t['balance']));

        return ['reservations' => $reservations->values()->all(), 'bar_tabs' => $tabs->values()->all(), 'total' => Money::toDecimal($total)];
    }

    // ---------------------------------------------------------- daily closing

    /**
     * The figures for one day: what the system expects, by method, and the
     * cash that should be in the drawer (P33).
     *
     * @return array<string, mixed>
     */
    public function day(CarbonImmutable $date): array
    {
        [$payments, $refunds] = $this->money($date, $date);
        $expenses = $this->expenses($date, $date);
        // Cash physically left the drawer for every cash expense that is not void or rejected.
        $cashExpenses = $expenses->filter(fn (Expense $e) => $e->method === ExpenseMethod::Cash && in_array($e->status, [ExpenseStatus::Approved, ExpenseStatus::Pending], true));
        $cashIn = $this->sum($payments->where('method', PaymentMethod::Cash), 'amount');
        $cashRefunds = $this->sum($refunds->filter(fn (Refund $r) => $r->method === RefundMethod::Cash), 'amount');
        $cashOut = $this->sum($cashExpenses, 'amount');
        $closing = DailyClosing::query()->where('property_id', Property::current()->id)->where('date', $date->toDateString())->with(['closedBy', 'reopenedBy'])->first();

        return [
            'date' => $date->toDateString(),
            'status' => $closing?->status ?? 'OPEN',
            'received' => Money::toDecimal($this->sum($payments, 'amount')),
            'by_method' => $this->byMethod($payments),
            'hotel' => Money::toDecimal($this->sum($payments->where('payable_type', 'reservation'), 'amount')),
            'bar' => Money::toDecimal($this->sum($payments->where('payable_type', 'bar_tab'), 'amount')),
            'refunds' => [
                'total' => Money::toDecimal($this->sum($refunds, 'amount')),
                'count' => $refunds->count(),
                'cash' => Money::toDecimal($cashRefunds),
            ],
            'expenses' => [
                'approved' => Money::toDecimal($this->sum($expenses->where('status', ExpenseStatus::Approved), 'amount')),
                'cash_paid' => Money::toDecimal($cashOut),
                'pending_count' => $expenses->where('status', ExpenseStatus::Pending)->count(),
                'lines' => $expenses->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Pending])->map(fn (Expense $e) => [
                    'number' => $e->number,
                    'category' => $e->category?->name,
                    'description' => $e->description,
                    'method' => $e->method->value,
                    'status' => $e->status->value,
                    'amount' => $e->amount,
                ])->values()->all(),
            ],
            'cash' => [
                'received' => Money::toDecimal($cashIn),
                'refunded' => Money::toDecimal($cashRefunds),
                'expenses' => Money::toDecimal($cashOut),
                'expected' => Money::toDecimal($cashIn - $cashRefunds - $cashOut),
            ],
            'needs_attention' => Payment::query()->where('property_id', Property::current()->id)->where('needs_attention', true)->count(),
            'closing' => $closing ? [
                'status' => $closing->status,
                'cash_expected' => $closing->cash_expected,
                'cash_counted' => $closing->cash_counted,
                'cash_difference' => $closing->cash_difference,
                'note' => $closing->note,
                'closed_by' => $closing->closedBy?->name,
                'closed_at' => $closing->closed_at?->toIso8601String(),
                'reopened_by' => $closing->reopenedBy?->name,
                'reopened_at' => $closing->reopened_at?->toIso8601String(),
                'reopen_reason' => $closing->reopen_reason,
                'summary' => $closing->summary,
            ] : null,
        ];
    }

    public function close(CarbonImmutable $date, string $cashCounted, ?string $note, User $actor): DailyClosing
    {
        // Compare calendar dates: $date is midnight in the app zone, today() midnight in the hotel's.
        if ($date->toDateString() > $this->settings->today()->toDateString()) {
            throw new BusinessRuleException('A day can only be closed once it has started.', 'DAY_NOT_STARTED', 422);
        }

        return DB::transaction(function () use ($date, $cashCounted, $note, $actor) {
            // Wait for in-flight desk payments / cash expenses, and hold new ones until committed.
            $this->days->lock();

            $existing = DailyClosing::query()
                ->where('property_id', Property::current()->id)
                ->where('date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing?->isClosed()) {
                throw new BusinessRuleException('This day is already closed.', 'DAY_ALREADY_CLOSED', 409);
            }

            $figures = $this->day($date);
            unset($figures['closing']);
            $expected = Money::toMinor($figures['cash']['expected']);
            $counted = Money::toMinor($cashCounted);
            $difference = $counted - $expected;

            if ($difference !== 0 && blank($note)) {
                throw new BusinessRuleException('The cash counted differs from the expected amount. Add a note explaining the difference.', 'NOTE_REQUIRED', 422);
            }

            $closing = $existing ?? new DailyClosing(['property_id' => Property::current()->id, 'date' => $date->toDateString()]);
            $closing->fill([
                'status' => DailyClosing::CLOSED,
                'summary' => $figures,
                'cash_expected' => Money::toDecimal($expected),
                'cash_counted' => Money::toDecimal($counted),
                'cash_difference' => Money::toDecimal($difference),
                'note' => $note,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $this->audit->record('finance.day_closed', $closing, null, [
                'date' => $date->toDateString(),
                'cash_expected' => $closing->cash_expected,
                'cash_counted' => $closing->cash_counted,
                'cash_difference' => $closing->cash_difference,
            ], ['note' => $note]);

            return $closing;
        });
    }

    public function reopen(CarbonImmutable $date, string $reason, User $actor): DailyClosing
    {
        $closing = DailyClosing::query()->where('property_id', Property::current()->id)->where('date', $date->toDateString())->first();

        if (! $closing?->isClosed()) {
            throw new BusinessRuleException('This day is not closed.', 'DAY_NOT_CLOSED', 409);
        }

        $closing->forceFill([
            'status' => DailyClosing::REOPENED,
            'reopened_by' => $actor->id,
            'reopened_at' => now(),
            'reopen_reason' => $reason,
        ])->save();

        $this->audit->record('finance.day_reopened', $closing, ['status' => 'CLOSED'], ['status' => 'REOPENED'], ['date' => $date->toDateString(), 'reason' => $reason]);

        return $closing;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Successful payments and completed refunds in the range (hotel days).
     *
     * @return array{0: Collection<int, Payment>, 1: Collection<int, Refund>}
     */
    public function money(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = $this->reports->window($from, $to);
        $property = Property::current()->id;

        $payments = Payment::query()
            ->where('property_id', $property)
            ->where('status', TransactionStatus::Successful->value)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->get(['id', 'payable_type', 'method', 'amount', 'paid_at']);

        $refunds = Refund::query()
            ->where('status', RefundStatus::Completed->value)
            ->where('completed_at', '>=', $start)->where('completed_at', '<', $end)
            ->whereHas('payment', fn ($q) => $q->where('property_id', $property))
            ->get(['id', 'payment_id', 'method', 'amount', 'completed_at']);

        return [$payments, $refunds];
    }

    /** @return Collection<int, Expense> */
    public function expenses(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Expense::query()
            ->where('property_id', Property::current()->id)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->with('category')
            ->orderBy('expense_date')
            ->get();
    }

    /** @return array{reservations: string, bar_tabs: string, total: string, count: int} */
    private function outstandingTotals(): array
    {
        $property = Property::current()->id;
        $hotel = DB::table('reservations')
            ->where('property_id', $property)
            ->whereIn('status', [ReservationStatus::CheckedIn->value, ReservationStatus::CheckedOut->value])
            ->whereRaw('(total + COALESCE(charges_total, 0)) > amount_paid')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total + COALESCE(charges_total, 0) - amount_paid), 0) AS owed')
            ->first();
        $bar = DB::table('bar_tabs')
            ->where('property_id', $property)
            ->where('status', BarTabStatus::Open->value)
            ->whereColumn('total', '>', 'amount_paid')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total - amount_paid), 0) AS owed')
            ->first();

        $h = Money::toMinor((string) $hotel->owed);
        $b = Money::toMinor((string) $bar->owed);

        return [
            'reservations' => Money::toDecimal($h),
            'bar_tabs' => Money::toDecimal($b),
            'total' => Money::toDecimal($h + $b),
            'count' => (int) $hotel->n + (int) $bar->n,
        ];
    }

    /** @return list<array{method: string, label: string, count: int, amount: string}> */
    private function byMethod(Collection $payments): array
    {
        return collect(PaymentMethod::cases())->map(fn (PaymentMethod $m) => [
            'method' => $m->value,
            'label' => $m->label(),
            'count' => $payments->where('method', $m)->count(),
            'amount' => Money::toDecimal($this->sum($payments->where('method', $m), 'amount')),
        ])->values()->all();
    }

    /** @return array<string, array{status: string, cash_difference: string}> */
    private function closings(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DailyClosing::query()
            ->where('property_id', Property::current()->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->mapWithKeys(fn (DailyClosing $c) => [$c->date->toDateString() => ['status' => $c->status, 'cash_difference' => $c->cash_difference]])
            ->all();
    }

    private function sum(Collection $rows, string $field): int
    {
        return (int) $rows->sum(fn ($row) => Money::toMinor((string) $row->{$field}));
    }

    private function localDate(?\DateTimeInterface $at): ?string
    {
        return $at ? CarbonImmutable::instance($at)->setTimezone(Property::current()->timezone)->toDateString() : null;
    }
}
