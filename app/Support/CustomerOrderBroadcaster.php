<?php

namespace App\Support;

use App\Events\CustomerOrderUpdated;

class CustomerOrderBroadcaster
{
    public static function notifyOrder(?int $customerId, int $orderId, string $reason = 'order_update', array $payload = []): void
    {
        if (! $customerId || $orderId <= 0) {
            return;
        }

        broadcast(new CustomerOrderUpdated($customerId, $orderId, $reason, $payload));
    }
}
