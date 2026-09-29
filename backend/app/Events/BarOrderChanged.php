<?php

namespace App\Events;

use App\Models\BarOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed to the bartender queue and waiter screens (spec §25, Reverb in M4).
 * With BROADCAST_CONNECTION=log it is only logged; the screens also poll.
 */
class BarOrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly BarOrder $order, public readonly int $propertyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('bar.'.$this->propertyId)];
    }

    public function broadcastAs(): string
    {
        return 'order.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'number' => $this->order->number,
            'status' => $this->order->status->value,
            'tab_id' => $this->order->tab_id,
        ];
    }
}
