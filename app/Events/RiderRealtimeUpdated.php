<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiderRealtimeUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public array $channels,
        public string $reason = 'order_update'
    ) {}

    public function broadcastOn(): array
    {
        return array_map(
            fn (string $name) => new PrivateChannel($name),
            array_values(array_unique($this->channels))
        );
    }

    public function broadcastAs(): string
    {
        return 'rider.realtime.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
