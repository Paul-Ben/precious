<?php

namespace App\Domain\Bar;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Domain\Stays\ChargeAmounts;
use App\Domain\Stays\FolioService;
use App\Enums\BarOrderStatus;
use App\Enums\BarTableStatus;
use App\Enums\BarTabStatus;
use App\Enums\ChargeCategory;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Events\BarOrderChanged;
use App\Exceptions\BusinessRuleException;
use App\Models\BarOrder;
use App\Models\BarOrderItem;
use App\Models\BarProduct;
use App\Models\BarTab;
use App\Models\BarTable;
use App\Models\DocumentSequence;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\User;
use App\Notifications\BarBillNotification;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bar tabs and orders (spec §22-28, ASSUMPTIONS P9-P11, P19-P22).
 *
 * A tab is one visit's running bill; each round is an order that goes
 * through the bartender queue. Totals: subtotal of non-cancelled orders,
 * minus discount, + service charge, + VAT on (subtotal − discount + SC).
 */
class TabService
{
    public function __construct(
        private readonly HotelSettings $settings,
        private readonly FolioService $folio,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{table_id?: ?int, customer_name?: ?string, customer_phone?: ?string, customer_email?: ?string}  $data
     */
    public function open(array $data, User $waiter): BarTab
    {
        return DB::transaction(function () use ($data, $waiter) {
            $property = Property::current();
            $table = null;

            if (! empty($data['table_id'])) {
                /** @var BarTable $table */
                $table = BarTable::query()->where('property_id', $property->id)->where('is_active', true)->lockForUpdate()->findOrFail($data['table_id']);

                if ($table->status === BarTableStatus::Blocked) {
                    throw new BusinessRuleException("{$table->name} is not in use.", 'TABLE_BLOCKED', 409);
                }

                $table->forceFill(['status' => BarTableStatus::Occupied])->save();
            }

            $tab = BarTab::create([
                'number' => DocumentSequence::next('TAB', (int) $this->settings->today()->year),
                'property_id' => $property->id,
                'table_id' => $table?->id,
                'waiter_id' => $waiter->id,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => isset($data['customer_email']) ? mb_strtolower((string) $data['customer_email']) : null,
                'status' => BarTabStatus::Open,
                'pay_token' => Str::random(40),
                'opened_at' => now(),
            ]);

            $this->audit->record('bar.tab_opened', $tab, null, ['number' => $tab->number, 'table' => $table?->name], [], $waiter);

            return $tab;
        });
    }

    /** @param  array{customer_name?: ?string, customer_phone?: ?string, customer_email?: ?string}  $data */
    public function updateCustomer(BarTab $tab, array $data): BarTab
    {
        if (! $tab->isOpen()) {
            throw new BusinessRuleException('This bill is closed.', 'TAB_CLOSED', 409);
        }

        if (array_key_exists('customer_email', $data) && $data['customer_email']) {
            $data['customer_email'] = mb_strtolower($data['customer_email']);
        }

        $tab->fill($data)->save();

        return $tab;
    }

    /**
     * Sends a round to the bar (P20: the customer is emailed the running bill).
     *
     * @param  list<array{product_id: int, quantity: int, notes?: ?string}>  $items
     */
    public function placeOrder(BarTab $tab, array $items, ?string $notes, User $waiter): BarOrder
    {
        $order = DB::transaction(function () use ($tab, $items, $notes, $waiter) {
            $locked = $this->lock($tab);

            if (! $locked->isOpen()) {
                throw new BusinessRuleException('This bill is closed. Open a new one.', 'TAB_CLOSED', 409);
            }

            $products = BarProduct::query()
                ->where('property_id', $locked->property_id)
                ->whereIn('id', collect($items)->pluck('product_id'))
                ->get()
                ->keyBy('id');

            $lines = [];
            $subtotal = 0;

            foreach ($items as $item) {
                /** @var BarProduct|null $product */
                $product = $products[$item['product_id']] ?? null;

                if (! $product || ! $product->is_active) {
                    throw new BusinessRuleException('One of the items is no longer on the menu.', 'PRODUCT_UNAVAILABLE', 422);
                }

                if (! $product->is_available) {
                    throw new BusinessRuleException("{$product->name} is sold out.", 'PRODUCT_UNAVAILABLE', 422, ['product_id' => $product->id]);
                }

                $unit = Money::toMinor($product->price);
                $qty = (int) $item['quantity'];
                $subtotal += $unit * $qty;
                $lines[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'unit_price' => Money::toDecimal($unit),
                    'quantity' => $qty,
                    'line_total' => Money::toDecimal($unit * $qty),
                    'notes' => $item['notes'] ?? null,
                ];
            }

            $order = BarOrder::create([
                'number' => DocumentSequence::next('ORD', (int) $this->settings->today()->year, 6),
                'tab_id' => $locked->id,
                'waiter_id' => $waiter->id,
                'status' => BarOrderStatus::Placed,
                'notes' => $notes,
                'subtotal' => Money::toDecimal($subtotal),
                'placed_at' => now(),
            ]);

            foreach ($lines as $line) {
                BarOrderItem::create([...$line, 'order_id' => $order->id]);
            }

            $this->recalculate($locked);

            $this->audit->record('bar.order_placed', $order, null, [
                'number' => $order->number,
                'tab' => $locked->number,
                'subtotal' => $order->subtotal,
                'items' => count($lines),
            ], [], $waiter);

            return $order;
        });

        $this->broadcast($order);
        $this->emailBill($tab->refresh(), final: false);

        return $order;
    }

    /**
     * Bartender / waiter status changes. Forward only:
     * PLACED → ACCEPTED → PREPARING → READY → DELIVERED.
     */
    public function advance(BarOrder $order, BarOrderStatus $to, User $actor): BarOrder
    {
        $updated = DB::transaction(function () use ($order, $to, $actor) {
            /** @var BarOrder $locked */
            $locked = BarOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $flow = [BarOrderStatus::Placed, BarOrderStatus::Accepted, BarOrderStatus::Preparing, BarOrderStatus::Ready, BarOrderStatus::Delivered];
            $fromIndex = array_search($from, $flow, true);
            $toIndex = array_search($to, $flow, true);

            if ($fromIndex === false || $toIndex === false || $toIndex <= $fromIndex || ($to === BarOrderStatus::Delivered && $from !== BarOrderStatus::Ready)) {
                throw new BusinessRuleException("An order that is {$from->value} cannot be moved to {$to->value}.", 'INVALID_STATUS', 409);
            }

            $stamp = match ($to) {
                BarOrderStatus::Accepted => ['accepted_at' => now(), 'accepted_by' => $actor->id],
                BarOrderStatus::Preparing => ['preparing_at' => now()] + ($locked->accepted_at ? [] : ['accepted_at' => now(), 'accepted_by' => $actor->id]),
                BarOrderStatus::Ready => ['ready_at' => now()] + ($locked->accepted_at ? [] : ['accepted_at' => now(), 'accepted_by' => $actor->id]),
                BarOrderStatus::Delivered => ['delivered_at' => now(), 'delivered_by' => $actor->id],
                default => [],
            };

            $locked->forceFill(['status' => $to, ...$stamp])->save();

            return $locked;
        });

        $this->broadcast($updated);

        return $updated;
    }

    /**
     * P21: the waiter who placed it can cancel until the bar accepts it;
     * afterwards only bar.orders.cancel, always with a reason. Delivered
     * drinks are not cancelled - use a discount.
     */
    public function cancelOrder(BarOrder $order, ?string $reason, User $actor, bool $canCancelAny): BarOrder
    {
        $updated = DB::transaction(function () use ($order, $reason, $actor, $canCancelAny) {
            /** @var BarOrder $locked */
            $locked = BarOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $tab = $this->lock($locked->tab);

            if (! $locked->status->isInProgress() || ! $tab->isOpen()) {
                throw new BusinessRuleException('Only orders still with the bar can be cancelled.', 'INVALID_STATUS', 409);
            }

            $ownEarly = $locked->status === BarOrderStatus::Placed && $locked->waiter_id === $actor->id;

            if (! $ownEarly && ! $canCancelAny) {
                throw new BusinessRuleException('The bar has started on this order. Ask a manager to cancel it.', 'FORBIDDEN', 403);
            }

            if (! $ownEarly && blank($reason)) {
                throw new BusinessRuleException('Give a reason for cancelling.', 'REASON_REQUIRED', 422);
            }

            $old = $locked->status->value;
            $locked->forceFill([
                'status' => BarOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->recalculate($tab);

            $this->audit->record('bar.order_cancelled', $locked, ['status' => $old], ['status' => 'CANCELLED'], [
                'tab' => $tab->number,
                'subtotal' => $locked->subtotal,
                'reason' => $reason,
            ], $actor);

            return $locked;
        });

        $this->broadcast($updated);

        return $updated;
    }

    /** P22: discounts by managers only (discounts.apply), with a reason. */
    public function applyDiscount(BarTab $tab, string $amount, string $reason, User $actor): BarTab
    {
        return DB::transaction(function () use ($tab, $amount, $reason, $actor) {
            $locked = $this->lock($tab);

            if (! $locked->isOpen()) {
                throw new BusinessRuleException('This bill is closed.', 'TAB_CLOSED', 409);
            }

            $minor = Money::toMinor($amount);

            if ($minor < 0 || $minor > Money::toMinor($locked->subtotal)) {
                throw new BusinessRuleException('The discount cannot be more than the items total.', 'INVALID_AMOUNT', 422);
            }

            $old = $locked->discount;
            $locked->forceFill(['discount' => Money::toDecimal($minor), 'discount_reason' => $reason, 'discount_by' => $actor->id]);
            $this->recalculate($locked);

            $this->audit->record('bar.discount_applied', $locked, ['discount' => $old], ['discount' => $locked->discount], ['reason' => $reason], $actor);

            return $locked;
        });
    }

    /**
     * P9: charge the whole bill to an in-house guest, verified by room
     * number + surname. Closes the tab; the guest signs the printed slip.
     */
    public function chargeToRoom(BarTab $tab, string $roomNumber, string $surname, User $actor): BarTab
    {
        return DB::transaction(function () use ($tab, $roomNumber, $surname, $actor) {
            $locked = $this->lock($tab);
            $this->assertSettleable($locked);

            if (Money::toMinor($locked->amount_paid) > 0) {
                throw new BusinessRuleException('Part of this bill is already paid. Take the rest as a payment instead.', 'PARTLY_PAID', 409);
            }

            if (Money::toMinor($locked->total) <= 0) {
                throw new BusinessRuleException('There is nothing to charge.', 'NOTHING_DUE', 422);
            }

            $surname = mb_strtolower(trim($surname));

            /** @var Stay|null $stay */
            $stay = Stay::query()
                ->where('property_id', $locked->property_id)
                ->where('status', StayStatus::Open->value)
                ->whereHas('room', fn (Builder $q) => $q->whereRaw('LOWER(number) = ?', [mb_strtolower(trim($roomNumber))]))
                ->whereHas('reservation', fn (Builder $r) => $r
                    ->where('status', ReservationStatus::CheckedIn->value)
                    ->whereHas('guests', fn (Builder $g) => $g->whereRaw('LOWER(last_name) = ?', [$surname])))
                ->first();

            if (! $stay) {
                // Same message whether the room or the name is wrong (no guessing who is in a room).
                throw new BusinessRuleException('No checked-in guest with that surname is in that room.', 'ROOM_GUEST_MISMATCH', 422);
            }

            /** @var Reservation $reservation */
            $reservation = Reservation::query()->whereKey($stay->reservation_id)->lockForUpdate()->firstOrFail();
            $net = Money::toMinor($locked->subtotal) - Money::toMinor($locked->discount);

            $charge = $this->folio->post(
                $reservation,
                ChargeCategory::Bar,
                "Bar bill {$locked->number}".($locked->table ? " ({$locked->table->name})" : ''),
                new ChargeAmounts($net, '1.00', $net, Money::toMinor($locked->service_charge), Money::toMinor($locked->vat)),
                $stay,
                $actor,
            );
            $charge->forceFill(['bar_tab_id' => $locked->id])->save();

            $locked->forceFill([
                'status' => BarTabStatus::Closed,
                'settlement' => 'CHARGED_TO_ROOM',
                'stay_id' => $stay->id,
                'reservation_charge_id' => $charge->id,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->releaseTable($locked);

            $this->audit->record('bar.charged_to_room', $locked, null, [
                'room' => $stay->room?->number,
                'reservation' => $reservation->number,
                'total' => $locked->total,
            ], [], $actor);

            return $locked->load(['table', 'stay.room']);
        });
    }

    /** Settles a fully paid tab (or cancels an empty one) and frees the table. */
    public function close(BarTab $tab, User $actor, ?string $note = null): BarTab
    {
        $closed = DB::transaction(function () use ($tab, $actor, $note) {
            $locked = $this->lock($tab);
            $this->assertSettleable($locked);
            $empty = Money::toMinor($locked->total) === 0 && Money::toMinor($locked->amount_paid) === 0;

            if (! $empty && $locked->balanceMinor() > 0) {
                throw new BusinessRuleException(
                    'The customer still owes '.Money::format($locked->balanceMinor()).'.',
                    'BALANCE_DUE',
                    409,
                    ['balance' => Money::toDecimal($locked->balanceMinor())]
                );
            }

            $locked->forceFill([
                'status' => $empty ? BarTabStatus::Cancelled : BarTabStatus::Closed,
                'settlement' => $empty ? null : 'PAID',
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'close_note' => $note,
            ])->save();

            $this->releaseTable($locked);

            $this->audit->record($empty ? 'bar.tab_cancelled' : 'bar.tab_closed', $locked, ['status' => 'OPEN'], ['status' => $locked->status->value], [
                'total' => $locked->total,
                'paid' => $locked->amount_paid,
            ], $actor);

            return $locked;
        });

        if ($closed->status === BarTabStatus::Closed) {
            $this->emailBill($closed, final: true);
        }

        return $closed;
    }

    public function recalculate(BarTab $tab): void
    {
        $subtotal = (int) BarOrder::query()
            ->where('tab_id', $tab->id)
            ->where('status', '!=', BarOrderStatus::Cancelled->value)
            ->pluck('subtotal')
            ->sum(fn ($v) => Money::toMinor((string) $v));

        $settings = $this->settings->all();
        $discount = min(Money::toMinor($tab->discount ?? '0'), $subtotal);
        $net = $subtotal - $discount;
        $serviceCharge = Money::percentOf($net, (string) $settings['service_charge_percent']);
        $vat = Money::percentOf($net + $serviceCharge, (string) $settings['vat_percent']);

        $tab->forceFill([
            'subtotal' => Money::toDecimal($subtotal),
            'discount' => Money::toDecimal($discount),
            'service_charge' => Money::toDecimal($serviceCharge),
            'vat' => Money::toDecimal($vat),
            'total' => Money::toDecimal($net + $serviceCharge + $vat),
        ])->save();
    }

    /** P20: running bill (after each order) and final bill, with the pay link. */
    public function emailBill(BarTab $tab, bool $final, ?string $to = null): bool
    {
        $to ??= $tab->customer_email;

        if (! $to) {
            return false;
        }

        try {
            Notification::route('mail', $to)->notify(new BarBillNotification($tab->load(['table', 'orders.items']), $final));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    private function assertSettleable(BarTab $tab): void
    {
        if (! $tab->isOpen()) {
            throw new BusinessRuleException('This bill is already closed.', 'TAB_CLOSED', 409);
        }

        $pending = BarOrder::query()
            ->where('tab_id', $tab->id)
            ->whereIn('status', [BarOrderStatus::Placed->value, BarOrderStatus::Accepted->value, BarOrderStatus::Preparing->value, BarOrderStatus::Ready->value])
            ->count();

        if ($pending > 0) {
            throw new BusinessRuleException("{$pending} order(s) are still with the bar. Deliver or cancel them first.", 'ORDERS_PENDING', 409);
        }
    }

    private function releaseTable(BarTab $tab): void
    {
        if (! $tab->table_id) {
            return;
        }

        $stillOpen = BarTab::query()->where('table_id', $tab->table_id)->where('status', BarTabStatus::Open->value)->whereKeyNot($tab->id)->exists();

        if (! $stillOpen) {
            BarTable::query()->whereKey($tab->table_id)->where('status', BarTableStatus::Occupied->value)
                ->update(['status' => BarTableStatus::Available->value, 'updated_at' => now()]);
        }
    }

    private function broadcast(BarOrder $order): void
    {
        try {
            BarOrderChanged::dispatch($order, (int) $order->tab->property_id);
        } catch (Throwable $e) {
            // Live updates are a convenience; screens also poll.
            report($e);
        }
    }

    private function lock(BarTab $tab): BarTab
    {
        return BarTab::query()->whereKey($tab->id)->lockForUpdate()->firstOrFail();
    }
}
