<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Domain\Reports\ReportService;
use App\Enums\BarTabStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\BarTab;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Support\ApiResponse;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly ReportService $reports,
        private readonly HotelSettings $settings,
        private readonly AuditService $audit,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->reports->summary($from, $to));
    }

    /** CSV downloads (reports.export). */
    public function export(Request $request): StreamedResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(['payments', 'bar-sales', 'reservations'])]])['type'];
        [$from, $to] = $this->range($request);
        [$start, $end] = $this->reports->window($from, $to);
        $property = Property::current();
        $propertyId = $property->id;
        // Timestamps are stored in the app time zone; show them in hotel time.
        $local = fn ($at) => $at ? CarbonImmutable::instance($at)->setTimezone($property->timezone)->format('Y-m-d H:i') : null;

        $this->audit->record('reports.exported', null, null, null, ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        [$header, $rows] = match ($type) {
            'payments' => [
                ['Paid at', 'Reference', 'Receipt', 'For', 'Number', 'Method', 'Gateway', 'Purpose', 'Amount', 'Processing fee', 'Refunded', 'External reference', 'Recorded by'],
                fn () => Payment::query()
                    ->where('property_id', $propertyId)
                    ->where('status', TransactionStatus::Successful->value)
                    ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
                    ->with(['receipt', 'recordedBy', 'payable'])
                    ->orderBy('paid_at')
                    ->lazy(500)
                    ->map(fn (Payment $p) => [
                        $local($p->paid_at), $p->reference, $p->receipt?->number,
                        $p->payable_type === 'bar_tab' ? 'Bar' : 'Hotel', $p->payable?->number,
                        $p->method->label(), $p->gateway, $p->purpose->value, $p->amount, $p->customer_fee,
                        $p->refunded_amount, $p->external_reference, $p->recordedBy?->name,
                    ]),
            ],
            'bar-sales' => [
                ['Closed at', 'Bill', 'Table', 'Waiter', 'Items', 'Discount', 'Service charge', 'VAT', 'Total', 'Settlement'],
                fn () => BarTab::query()
                    ->where('property_id', $propertyId)
                    ->where('status', BarTabStatus::Closed->value)
                    ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)
                    ->with(['table', 'waiter'])
                    ->orderBy('closed_at')
                    ->lazy(500)
                    ->map(fn (BarTab $t) => [
                        $local($t->closed_at), $t->number, $t->table?->name, $t->waiter?->name,
                        $t->subtotal, $t->discount, $t->service_charge, $t->vat, $t->total,
                        $t->settlement === 'CHARGED_TO_ROOM' ? 'Charged to room' : 'Paid',
                    ]),
            ],
            'reservations' => [
                ['Number', 'Guest', 'Source', 'Status', 'Check-in', 'Check-out', 'Nights', 'Accommodation', 'Extras', 'Total', 'Paid', 'Balance'],
                fn () => Reservation::query()
                    ->where('property_id', $propertyId)
                    ->where('check_in', '<=', $to->toDateString())
                    ->where('check_out', '>', $from->toDateString())
                    ->with('guest')
                    ->orderBy('check_in')
                    ->lazy(500)
                    ->map(fn (Reservation $r) => [
                        $r->number, $r->guest?->fullName(), $r->source->value, $r->status->value,
                        $r->check_in->toDateString(), $r->check_out->toDateString(), $r->nights,
                        $r->total, $r->charges_total, Money::toDecimal($r->grandTotalMinor()), $r->amount_paid,
                        Money::toDecimal($r->balanceMinor()),
                    ]),
            ],
        };

        $filename = "{$type}_{$from->toDateString()}_{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel shows ₦ and names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            // Explicit empty escape: RFC 4180 output, and PHP 8.4 deprecates relying on the default.
            fputcsv($out, $header, ',', '"', '');

            foreach ($rows() as $row) {
                // Neutralise spreadsheet formulas in free-text cells (CSV injection).
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v, $row), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = $this->settings->today();
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['to']) : CarbonImmutable::createFromFormat('!Y-m-d', $today->toDateString());
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['from']) : $to->subDays(6);

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }

        if ((int) round($from->diffInDays($to)) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a range of up to one year.']);
        }

        return [$from, $to];
    }
}
