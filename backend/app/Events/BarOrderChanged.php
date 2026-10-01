<?php

namespace App\Events;

use App\Models\BarOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed to the bartender queue and waiter screens over Reverb (spec §25).
 * The screens also poll, so a missing Reverb server only slows updates.
 */
class BarOrderChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly BarOrder $order, public readonly int $propertyId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('bar')];
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
            'property_id' => $this->propertyId,
        ];
    }
}
