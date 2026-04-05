<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CustomerOrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $customerId,
        public int $orderId,
        public string $reason = 'order_update',
        public array $payload = [],
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("customer.{$this->customerId}");
    }

    public function broadcastAs(): string
    {
        return 'customer.order.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601String(),
            ...$this->payload,
        ];
    }
}
