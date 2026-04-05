<?php

namespace App\Support;

use App\Events\RiderRealtimeUpdated;

class RiderRealtimeBroadcaster
{
    public static function notifyPool(string $reason = 'order_pool_update'): void
    {
        broadcast(new RiderRealtimeUpdated(['rider.pool'], $reason));
    }

    public static function notifyRider(?int $riderId, string $reason = 'rider_order_update'): void
    {
        if (! $riderId) {
            return;
        }

        broadcast(new RiderRealtimeUpdated(["rider.{$riderId}"], $reason));
    }

    public static function notifyRiderAndPool(?int $riderId, string $reason = 'rider_and_pool_update'): void
    {
        $channels = ['rider.pool'];
        if ($riderId) {
            $channels[] = "rider.{$riderId}";
        }

        broadcast(new RiderRealtimeUpdated($channels, $reason));
    }
}
