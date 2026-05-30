<?php

namespace App\Support;

use App\Events\CustomerOrderUpdated;
use App\Models\Order;

class CustomerOrderBroadcaster
{
    public static function notifyOrder(?int $customerId, int $orderId, string $reason = 'order_update', array $payload = []): void
    {
        if (! $customerId || $orderId <= 0) {
            return;
        }

        broadcast(new CustomerOrderUpdated($customerId, $orderId, $reason, $payload));
        self::sendPush($customerId, $orderId, $reason, $payload);
    }

    private static function sendPush(int $customerId, int $orderId, string $reason, array $payload): void
    {
        if ($reason === 'rider_location_updated') {
            return;
        }

        $order = Order::query()
            ->select(['id', 'order_number', 'status', 'restaurant_id'])
            ->with('restaurant:id,name')
            ->find($orderId);

        if (! $order) {
            return;
        }

        [$title, $body] = self::copyFor($order, $reason);

        app(ExpoPushService::class)->sendToUser($customerId, $title, $body, [
            'screen' => 'order',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'reason' => $reason,
            'status' => $order->status,
            ...$payload,
        ]);
    }

    private static function copyFor(Order $order, string $reason): array
    {
        $restaurant = $order->restaurant?->name ?? 'Your restaurant';
        $status = str_replace('_', ' ', $order->status);

        return match ($reason) {
            'customer_order_created' => [
                'Order placed',
                "{$restaurant} received {$order->order_number}.",
            ],
            'rider_order_claimed', 'admin_rider_assignment_changed' => [
                'Rider assigned',
                "A rider is now handling {$order->order_number}.",
            ],
            'customer_cancel_requested' => [
                'Cancellation request sent',
                "We sent your request for {$order->order_number} to support.",
            ],
            'customer_issue_created' => [
                'Support request received',
                "Support will review your concern for {$order->order_number}.",
            ],
            default => [
                'Order update',
                "{$order->order_number} is now {$status}.",
            ],
        };
    }
}
