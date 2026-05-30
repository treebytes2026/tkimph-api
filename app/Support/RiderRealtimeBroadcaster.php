<?php

namespace App\Support;

use App\Events\RiderRealtimeUpdated;
use App\Models\User;

class RiderRealtimeBroadcaster
{
    public static function notifyPool(string $reason = 'order_pool_update'): void
    {
        broadcast(new RiderRealtimeUpdated(['rider.pool'], $reason));

        if ($reason === 'customer_order_created') {
            app(ExpoPushService::class)->sendToRole(
                User::ROLE_RIDER,
                'New delivery job',
                'A new order is waiting in the rider job pool.',
                ['screen' => 'rider_jobs', 'reason' => $reason],
                fn ($query) => $query->where('is_active', true)
            );
        }
    }

    public static function notifyRider(?int $riderId, string $reason = 'rider_order_update'): void
    {
        if (! $riderId) {
            return;
        }

        broadcast(new RiderRealtimeUpdated(["rider.{$riderId}"], $reason));
        self::pushToRider($riderId, $reason);
    }

    public static function notifyRiderAndPool(?int $riderId, string $reason = 'rider_and_pool_update'): void
    {
        $channels = ['rider.pool'];
        if ($riderId) {
            $channels[] = "rider.{$riderId}";
        }

        broadcast(new RiderRealtimeUpdated($channels, $reason));
        if ($riderId) {
            self::pushToRider($riderId, $reason);
        }
    }

    private static function pushToRider(int $riderId, string $reason): void
    {
        $copy = match ($reason) {
            'admin_rider_assignment_changed' => ['Delivery assignment updated', 'Check your active delivery details.'],
            'rider_order_claimed' => ['Delivery claimed', 'The job is now in your rider dashboard.'],
            'partner_order_status_changed' => ['Restaurant updated an order', 'Check your active delivery before moving.'],
            default => [null, null],
        };

        if (! $copy[0] || ! $copy[1]) {
            return;
        }

        app(ExpoPushService::class)->sendToUser($riderId, $copy[0], $copy[1], [
            'screen' => 'rider_jobs',
            'reason' => $reason,
        ]);
    }
}
