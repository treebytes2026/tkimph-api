<?php

namespace App\Support;

use App\Models\AdminSetting;
use App\Models\OrderIssue;
use App\Models\Order;
use App\Models\SupportNote;
use App\Models\User;
use Illuminate\Support\Carbon;

class OrderWorkflow
{
    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = [
            Order::STATUS_PENDING => [Order::STATUS_ACCEPTED, Order::STATUS_CANCELLED, Order::STATUS_FAILED],
            Order::STATUS_ACCEPTED => [Order::STATUS_PREPARING, Order::STATUS_CANCELLED, Order::STATUS_FAILED],
            Order::STATUS_PREPARING => [Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_CANCELLED, Order::STATUS_FAILED],
            Order::STATUS_OUT_FOR_DELIVERY => [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, Order::STATUS_UNDELIVERABLE, Order::STATUS_FAILED],
            Order::STATUS_COMPLETED => [],
            Order::STATUS_CANCELLED => [],
            Order::STATUS_FAILED => [],
            Order::STATUS_UNDELIVERABLE => [],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            abort(422, "Status transition from '{$from}' to '{$to}' is not allowed.");
        }
    }

    public static function partnerMayCancel(Order $order): bool
    {
        $cancelWindow = AdminSetting::readInt('partner_cancel_window_minutes', 15);
        $placedAt = $order->placed_at ?? Carbon::now();

        return in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_ACCEPTED], true)
            && $placedAt->greaterThanOrEqualTo(now()->subMinutes($cancelWindow));
    }

    public static function settlementFields(float $subtotal, float $deliveryFee): array
    {
        $platformCommission = PlatformPricing::commissionAmount($subtotal);

        return [
            'gross_sales' => round($subtotal, 2),
            'service_fee' => $platformCommission,
            'restaurant_net' => PlatformPricing::restaurantNet($subtotal),
            'delivery_fee' => round($deliveryFee, 2),
            'commission_rate' => PlatformPricing::commissionRate(),
        ];
    }

    public static function customerMayRequestCancellation(Order $order): bool
    {
        $cancelWindow = AdminSetting::readInt('customer_cancel_window_minutes', 5);
        $placedAt = $order->placed_at ?? Carbon::now();

        return in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_ACCEPTED], true)
            && $placedAt->greaterThanOrEqualTo(now()->subMinutes($cancelWindow));
    }

    public static function syncRefundStatusForException(Order $order, ?string $reason = null): void
    {
        $cancellationLike = in_array($order->status, [
            Order::STATUS_CANCELLED,
            Order::STATUS_FAILED,
            Order::STATUS_UNDELIVERABLE,
        ], true);

        if (! $cancellationLike) {
            return;
        }

        if ($order->payment_status === 'paid') {
            $order->update([
                'refund_status' => Order::REFUND_STATUS_PENDING,
                'refund_requested_at' => now(),
                'refund_reason' => $reason ?: 'Auto-opened due to paid order exception.',
            ]);
        } else {
            $order->update([
                'refund_status' => Order::REFUND_STATUS_NOT_REQUIRED,
            ]);
        }
    }

    public static function createOrderIssue(
        Order $order,
        User $customer,
        string $type,
        string $subject,
        string $description,
        array $meta = []
    ): OrderIssue {
        return $order->issues()->create([
            'customer_id' => $customer->id,
            'issue_type' => $type,
            'status' => OrderIssue::STATUS_OPEN,
            'subject' => $subject,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }

    public static function recordEvent(
        Order $order,
        string $eventType,
        ?User $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null,
        array $meta = []
    ): void {
        $order->events()->create([
            'actor_user_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'meta' => $meta ?: null,
        ]);
    }

    public static function addSupportNote(
        ?int $restaurantId,
        ?int $orderId,
        User $admin,
        string $type,
        string $body
    ): SupportNote {
        return SupportNote::query()->create([
            'restaurant_id' => $restaurantId,
            'order_id' => $orderId,
            'admin_id' => $admin->id,
            'note_type' => $type,
            'body' => $body,
        ]);
    }
}
