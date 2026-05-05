<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use App\Support\CustomerOrderBroadcaster;
use App\Support\CommissionCollectionMonitor;
use App\Support\OrderWorkflow;
use App\Support\PlatformPricing;
use App\Support\RiderRealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->partner($request);

        $restaurantIds = $user->restaurants()->pluck('id');
        $status = $request->query('status');

        $query = Order::query()
            ->with([
                'items',
                'customer:id,name,phone',
                'restaurant:id,name',
                'events.actor:id,name,email,role',
            ])
            ->whereIn('restaurant_id', $restaurantIds)
            ->orderByDesc('id');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $orders = $query->paginate($request->integer('per_page', 20));
        $orders->getCollection()->transform(fn (Order $order) => $this->serializeOrder($order));

        return response()
            ->json($orders)
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $user = $this->partner($request);
        $restaurantIds = $user->restaurants()->pluck('id');
        abort_unless($restaurantIds->contains($order->restaurant_id), 403, 'You do not manage this order.');

        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $fromStatus = $order->status;
        $nextStatus = $data['status'];

        if ($fromStatus === Order::STATUS_OUT_FOR_DELIVERY && $nextStatus !== $fromStatus) {
            abort(422, 'The rider must update this order after it is out for delivery.');
        }

        if ($nextStatus === Order::STATUS_COMPLETED) {
            abort(422, 'Only the rider can mark a delivery order as completed.');
        }

        if (AdminSetting::readBool('order_transition_guardrails', true)) {
            OrderWorkflow::assertTransition($fromStatus, $nextStatus);
        }

        if ($nextStatus === Order::STATUS_CANCELLED && ! OrderWorkflow::partnerMayCancel($order)) {
            abort(422, 'This order can no longer be cancelled by the partner.');
        }

        $isCancellationLike = in_array($nextStatus, [Order::STATUS_CANCELLED, Order::STATUS_FAILED], true);

        $order->update([
            'status' => $nextStatus,
            'cancelled_by_role' => $isCancellationLike ? $user->role : null,
            'cancellation_reason' => $isCancellationLike ? ($data['reason'] ?? null) : null,
            'cancelled_at' => $isCancellationLike ? now() : null,
        ]);

        if ($isCancellationLike) {
            OrderWorkflow::syncRefundStatusForException($order, $data['reason'] ?? null);
        }

        OrderWorkflow::recordEvent(
            $order,
            $isCancellationLike ? 'partner_order_exception' : 'partner_status_change',
            $user,
            $fromStatus,
            $nextStatus,
            $data['reason'] ?? null
        );

        User::query()
            ->admins()
            ->each(fn (User $admin) => $admin->notify(new AdminSystemNotification(
                $isCancellationLike ? 'order_cancelled' : 'order_status_changed',
                $order->order_number.' changed to '.str_replace('_', ' ', $nextStatus).'.',
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'restaurant_id' => $order->restaurant_id,
                    'restaurant_name' => $order->restaurant?->name,
                    'status' => $nextStatus,
                    'reason' => $data['reason'] ?? null,
                ]
            )));

        RiderRealtimeBroadcaster::notifyRiderAndPool($order->rider_id, 'partner_order_status_changed');
        CustomerOrderBroadcaster::notifyOrder($order->customer_id, $order->id, 'partner_order_status_changed');

        return response()->json([
            'message' => 'Order status updated.',
            'order' => $this->serializeOrder($order->fresh()->load(['items', 'customer:id,name,phone', 'restaurant:id,name', 'events.actor:id,name,email,role'])),
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $user = $this->partner($request);
        $restaurant = $user->restaurants()->orderBy('id')->first();
        abort_unless($restaurant, 404, 'No restaurant linked to this account.');

        $query = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', Order::STATUS_COMPLETED);

        if ($request->filled('date_from')) {
            $query->whereDate('placed_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('placed_at', '<=', $request->string('date_to')->toString());
        }

        $orders = $query->get();

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'restaurant_name' => $restaurant->name,
            'order_count' => $orders->count(),
            'gross_sales' => round((float) $orders->sum('gross_sales'), 2),
            'commission_rate' => PlatformPricing::commissionRate(),
            'platform_commission' => round((float) $orders->sum('service_fee'), 2),
            'delivery_fees' => round((float) $orders->sum('delivery_fee'), 2),
            'restaurant_net' => round((float) $orders->sum('restaurant_net'), 2),
            'payment_details' => [
                'gcash_name' => AdminSetting::read('commission_payment_gcash_name', ''),
                'gcash_number' => AdminSetting::read('commission_payment_gcash_number', ''),
            ],
        ]);
    }

    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'delivery_mode' => $order->delivery_mode,
            'delivery_address' => $order->delivery_address,
            'delivery_floor' => $order->delivery_floor,
            'delivery_note' => $order->delivery_note,
            'location_label' => $order->location_label,
            'subtotal' => (string) $order->subtotal,
            'service_fee' => (string) $order->service_fee,
            'delivery_fee' => (string) $order->delivery_fee,
            'gross_sales' => (string) ($order->gross_sales ?? $order->subtotal),
            'restaurant_net' => (string) ($order->restaurant_net ?? max(0, (float) $order->subtotal - (float) $order->service_fee)),
            'total' => (string) $order->total,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'cancelled_by_role' => $order->cancelled_by_role,
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'customer' => $order->customer ? ['id' => $order->customer->id, 'name' => $order->customer->name, 'phone' => $order->customer->phone] : null,
            'restaurant' => $order->restaurant ? ['id' => $order->restaurant->id, 'name' => $order->restaurant->name] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->name,
                'unit_price' => (string) $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
            'timeline' => $order->events->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                    'role' => $event->actor->role,
                ] : null,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function partner(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user && $user->isRestaurantOwner(), 403, 'Partner access only.');
        return $user;
    }
}
