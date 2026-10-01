<?php

namespace App\Domain\Reports;

use App\Enums\BarOrderStatus;
use App\Enums\BarTabStatus;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionStatus;
use App\Models\Property;
use App\Models\Room;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hotel and bar performance for a date range (hotel time, inclusive).
 *
 * Definitions (documented in ASSUMPTIONS A35):
 *  - room night = one room occupied for one night by a CONFIRMED, CHECKED_IN
 *    or CHECKED_OUT booking; room revenue = booked nightly rate (before tax);
 *  - occupancy = room nights / (active rooms × days); ADR = revenue / nights;
 *    RevPAR = revenue / (active rooms × days);
 *  - bar sales count bills closed in the range (net of discount, before tax);
 *  - payments = money actually received (successful), refunds = paid out.
 */
class ReportService
{
    /** Booking statuses that count as sold room nights. */
    private const SOLD = [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut];

    /**
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $property = Property::current();
        [$start, $end] = $this->window($from, $to);
        $days = (int) round($from->diffInDays($to)) + 1;
        $rooms = Room::query()->where('property_id', $property->id)->where('is_active', true)->count();
        $sold = array_map(fn (ReservationStatus $s) => $s->value, self::SOLD);
        $endDate = $to->addDay()->toDateString(); // exclusive

        // Room nights and revenue inside the range.
        $stay = DB::table('reservation_rooms as rr')
            ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('r.property_id', $property->id)
            ->whereIn('r.status', $sold)
            ->where('rr.is_active', true)
            ->where('rr.check_in', '<', $endDate)
            ->where('rr.check_out', '>', $from->toDateString())
            ->selectRaw('COALESCE(SUM(LEAST(rr.check_out, ?::date) - GREATEST(rr.check_in, ?::date)), 0) AS nights', [$endDate, $from->toDateString()])
            ->selectRaw('COALESCE(SUM((LEAST(rr.check_out, ?::date) - GREATEST(rr.check_in, ?::date)) * rr.nightly_rate), 0) AS revenue', [$endDate, $from->toDateString()])
            ->first();

        $nights = (int) $stay->nights;
        $roomRevenue = $this->minor($stay->revenue);
        $capacity = max(1, $rooms * $days);

        $extras = DB::table('reservation_charges as c')
            ->join('reservations as r', 'r.id', '=', 'c.reservation_id')
            ->where('r.property_id', $property->id)
            ->where('c.status', 'ACTIVE')
            ->where('c.category', '!=', 'BAR')
            ->where('c.created_at', '>=', $start)->where('c.created_at', '<', $end)
            ->groupBy('c.category')
            ->selectRaw('c.category, COUNT(*) AS lines, SUM(c.subtotal) AS subtotal, SUM(c.total) AS total')
            ->get();

        $bar = DB::table('bar_tabs')
            ->where('property_id', $property->id)
            ->where('status', BarTabStatus::Closed->value)
            ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)
            ->selectRaw('COUNT(*) AS bills, COALESCE(SUM(subtotal),0) AS subtotal, COALESCE(SUM(discount),0) AS discount, COALESCE(SUM(service_charge),0) AS service_charge, COALESCE(SUM(vat),0) AS vat, COALESCE(SUM(total),0) AS total')
            ->selectRaw("COALESCE(SUM(CASE WHEN settlement = 'CHARGED_TO_ROOM' THEN total ELSE 0 END),0) AS charged_to_room")
            ->first();

        $topProducts = DB::table('bar_order_items as i')
            ->join('bar_orders as o', 'o.id', '=', 'i.order_id')
            ->join('bar_tabs as t', 't.id', '=', 'o.tab_id')
            ->where('t.property_id', $property->id)
            ->where('t.status', BarTabStatus::Closed->value)
            ->where('t.closed_at', '>=', $start)->where('t.closed_at', '<', $end)
            ->where('o.status', BarOrderStatus::Delivered->value)
            ->groupBy('i.name')
            ->selectRaw('i.name, SUM(i.quantity) AS quantity, SUM(i.line_total) AS revenue')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $byWaiter = DB::table('bar_tabs as t')
            ->leftJoin('users as u', 'u.id', '=', 't.waiter_id')
            ->where('t.property_id', $property->id)
            ->where('t.status', BarTabStatus::Closed->value)
            ->where('t.closed_at', '>=', $start)->where('t.closed_at', '<', $end)
            ->groupBy('t.waiter_id', 'u.name')
            ->selectRaw("COALESCE(u.name, '—') AS name, COUNT(*) AS bills, SUM(t.total) AS total")
            ->orderByDesc('total')
            ->get();

        $payments = DB::table('payments')
            ->where('property_id', $property->id)
            ->where('status', TransactionStatus::Successful->value)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->groupBy('method', 'payable_type')
            ->selectRaw('method, payable_type, COUNT(*) AS count, SUM(amount) AS amount')
            ->get();

        $refunds = DB::table('refunds as f')
            ->join('payments as p', 'p.id', '=', 'f.payment_id')
            ->where('p.property_id', $property->id)
            ->where('f.status', RefundStatus::Completed->value)
            ->where('f.completed_at', '>=', $start)->where('f.completed_at', '<', $end)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(f.amount),0) AS amount')
            ->first();

        $received = $payments->sum(fn ($p) => $this->minor($p->amount));
        $barNet = $this->minor($bar->subtotal) - $this->minor($bar->discount);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'hotel' => [
                'rooms' => $rooms,
                'room_nights' => $nights,
                'occupancy_percent' => round($nights / $capacity * 100, 1),
                'room_revenue' => Money::toDecimal($roomRevenue),
                'adr' => Money::toDecimal($nights > 0 ? intdiv($roomRevenue, $nights) : 0),
                'revpar' => Money::toDecimal(intdiv($roomRevenue, $capacity)),
                'extras' => $extras->map(fn ($e) => [
                    'category' => $e->category,
                    'lines' => (int) $e->lines,
                    'subtotal' => Money::toDecimal($this->minor($e->subtotal)),
                    'total' => Money::toDecimal($this->minor($e->total)),
                ])->values()->all(),
            ],
            'bar' => [
                'bills' => (int) $bar->bills,
                'sales' => Money::toDecimal($barNet),
                'discounts' => Money::toDecimal($this->minor($bar->discount)),
                'service_charge' => Money::toDecimal($this->minor($bar->service_charge)),
                'vat' => Money::toDecimal($this->minor($bar->vat)),
                'total' => Money::toDecimal($this->minor($bar->total)),
                'charged_to_room' => Money::toDecimal($this->minor($bar->charged_to_room)),
                'average_bill' => Money::toDecimal((int) $bar->bills > 0 ? intdiv($this->minor($bar->total), (int) $bar->bills) : 0),
                'top_products' => $topProducts->map(fn ($p) => [
                    'name' => $p->name,
                    'quantity' => (int) $p->quantity,
                    'revenue' => Money::toDecimal($this->minor($p->revenue)),
                ])->values()->all(),
                'by_waiter' => $byWaiter->map(fn ($w) => [
                    'name' => $w->name,
                    'bills' => (int) $w->bills,
                    'total' => Money::toDecimal($this->minor($w->total)),
                ])->values()->all(),
            ],
            'payments' => [
                'received' => Money::toDecimal($received),
                'refunded' => Money::toDecimal($this->minor($refunds->amount)),
                'net' => Money::toDecimal($received - $this->minor($refunds->amount)),
                'by_method' => collect(PaymentMethod::cases())->map(fn (PaymentMethod $m) => [
                    'method' => $m->value,
                    'label' => $m->label(),
                    'amount' => Money::toDecimal($payments->where('method', $m->value)->sum(fn ($p) => $this->minor($p->amount))),
                ])->all(),
                'hotel' => Money::toDecimal($payments->where('payable_type', 'reservation')->sum(fn ($p) => $this->minor($p->amount))),
                'bar' => Money::toDecimal($payments->where('payable_type', 'bar_tab')->sum(fn ($p) => $this->minor($p->amount))),
            ],
            'daily' => $this->daily($from, $to, $rooms),
        ];
    }

    /**
     * One row per day: occupancy, room revenue, bar sales, money received.
     *
     * @return list<array<string, mixed>>
     */
    private function daily(CarbonImmutable $from, CarbonImmutable $to, int $rooms): array
    {
        $property = Property::current();
        $sold = array_map(fn (ReservationStatus $s) => $s->value, self::SOLD);
        $placeholders = implode(',', array_fill(0, count($sold), '?'));

        $roomRows = collect(DB::select(
            "SELECT d::date AS day, COUNT(rr.id) AS nights, COALESCE(SUM(rr.nightly_rate), 0) AS revenue
               FROM generate_series(?::date, ?::date, interval '1 day') AS d
               LEFT JOIN reservation_rooms rr
                      ON rr.check_in <= d::date AND rr.check_out > d::date AND rr.is_active
                     AND EXISTS (SELECT 1 FROM reservations r WHERE r.id = rr.reservation_id AND r.property_id = ? AND r.status IN ($placeholders))
              GROUP BY d ORDER BY d",
            [$from->toDateString(), $to->toDateString(), $property->id, ...$sold]
        ))->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        [$start, $end] = $this->window($from, $to);
        // Timestamps are stored without a zone in the app time zone; bucket them by the hotel's calendar day.
        $zones = [config('app.timezone'), $property->timezone];

        $barRows = DB::table('bar_tabs')
            ->where('property_id', $property->id)
            ->where('status', BarTabStatus::Closed->value)
            ->where('closed_at', '>=', $start)->where('closed_at', '<', $end)
            ->groupByRaw('1') // by position: PG treats repeated bound expressions as different
            ->selectRaw('DATE((closed_at AT TIME ZONE ?) AT TIME ZONE ?) AS day, SUM(subtotal - discount) AS sales', $zones)
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        $payRows = DB::table('payments')
            ->where('property_id', $property->id)
            ->where('status', TransactionStatus::Successful->value)
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)
            ->groupByRaw('1') // by position: PG treats repeated bound expressions as different
            ->selectRaw('DATE((paid_at AT TIME ZONE ?) AT TIME ZONE ?) AS day, SUM(amount) AS amount', $zones)
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        $out = [];

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $nights = (int) ($roomRows[$key]->nights ?? 0);

            $out[] = [
                'date' => $key,
                'room_nights' => $nights,
                'occupancy_percent' => $rooms > 0 ? round($nights / $rooms * 100, 1) : 0,
                'room_revenue' => Money::toDecimal($this->minor($roomRows[$key]->revenue ?? 0)),
                'bar_sales' => Money::toDecimal($this->minor($barRows[$key]->sales ?? 0)),
                'received' => Money::toDecimal($this->minor($payRows[$key]->amount ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * Range bounds as timestamps in the app time zone (how timestamps are stored).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = Property::current()->timezone;
        $appTz = config('app.timezone');

        return [
            CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay()->setTimezone($appTz),
            CarbonImmutable::parse($to->toDateString(), $tz)->addDay()->startOfDay()->setTimezone($appTz),
        ];
    }

    /** DB numeric (string/int/float/null) → kobo. */
    private function minor(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        // PostgreSQL returns numeric sums as exact strings ("12345.00"); keep them exact.
        if (is_string($value) && preg_match('/^(-?\d+)(?:\.(\d+))?$/', $value, $m)) {
            return Money::toMinor($m[1].'.'.substr(str_pad($m[2] ?? '0', 2, '0'), 0, 2));
        }

        return Money::toMinor(number_format((float) $value, 2, '.', ''));
    }
}
