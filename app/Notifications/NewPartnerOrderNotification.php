<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewPartnerOrderNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Order $order)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'new_order',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'restaurant_id' => $this->order->restaurant_id,
            'total' => (float) $this->order->total,
            'message' => 'New order received.',
            'placed_at' => optional($this->order->placed_at)->toIso8601String(),
        ];
    }
}
