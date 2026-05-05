<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomerOrderBroadcaster;
use App\Support\OrderWorkflow;
use App\Support\RiderRealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RiderOrderController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        return response()->json($this->overviewPayload($rider));
    }

    public function index(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $query = Order::query()
            ->where('rider_id', $rider->id)
            ->with(['customer:id,name,phone', 'restaurant:id,name,phone']);

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, Order::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        $orders = $query
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => $this->serializeOrder($order))->values()->all(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ]);
    }

    public function available(Request $request): JsonResponse
    {
        $this->rider($request);

        $orders = Order::query()
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_OUT_FOR_DELIVERY,
            ])
            ->whereNull('rider_id')
            ->with(['customer:id,name,phone', 'restaurant:id,name,phone'])
            ->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $order) => $this->serializeOrder($order))->values()->all(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'per_page' => $orders->perPage(),
            'total' => $orders->total(),
        ]);
    }

    public function setAvailability(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $rider->update(['is_active' => $data['is_active']]);
        RiderRealtimeBroadcaster::notifyRider($rider->id, 'rider_availability_changed');

        return response()->json([
            'id' => $rider->id,
            'is_active' => (bool) $rider->fresh()->is_active,
        ]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $rider = $this->rider($request);
        $this->assertAssignedOrder($order, $rider);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    Order::STATUS_ACCEPTED,
                    Order::STATUS_PREPARING,
                    Order::STATUS_OUT_FOR_DELIVERY,
                    Order::STATUS_COMPLETED,
                    Order::STATUS_FAILED,
                    Order::STATUS_UNDELIVERABLE,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        OrderWorkflow::assertTransition($order->status, $data['status']);

        $fromStatus = $order->status;
        $order->update([
            'status' => $data['status'],
            'cancelled_by_role' => in_array($data['status'], [Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE], true) ? $rider->role : null,
            'cancellation_reason' => in_array($data['status'], [Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE], true) ? ($data['note'] ?? null) : null,
            'cancelled_at' => in_array($data['status'], [Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE], true) ? now() : null,
        ]);

        if (in_array($data['status'], [Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE], true)) {
            OrderWorkflow::syncRefundStatusForException($order, $data['note'] ?? null);
        }

        OrderWorkflow::recordEvent(
            $order,
            'rider_status_change',
            $rider,
            $fromStatus,
            $data['status'],
            $data['note'] ?? null
        );
        RiderRealtimeBroadcaster::notifyRiderAndPool($rider->id, 'rider_order_status_changed');
        CustomerOrderBroadcaster::notifyOrder($order->customer_id, $order->id, 'rider_order_status_changed');

        return response()->json([
            'message' => 'Order status updated.',
            'order' => $this->serializeOrder($order->fresh()->load(['customer:id,name,phone', 'restaurant:id,name,phone'])),
        ]);
    }

    public function claim(Request $request, int $order): JsonResponse
    {
        $rider = $this->rider($request);
        $activeStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
            Order::STATUS_OUT_FOR_DELIVERY,
        ];

        $hasActiveOrder = Order::query()
            ->where('rider_id', $rider->id)
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($hasActiveOrder) {
            abort(409, 'Complete your current active order before claiming a new one.');
        }

        $claimed = DB::transaction(function () use ($order, $rider) {
            $lockedOrder = Order::query()->whereKey($order)->lockForUpdate()->firstOrFail();

            $claimableStatuses = [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_OUT_FOR_DELIVERY,
            ];

            if ((int) $lockedOrder->rider_id > 0 || ! in_array($lockedOrder->status, $claimableStatuses, true)) {
                abort(409, 'This order has already been claimed by another rider.');
            }

            $fromStatus = $lockedOrder->status;
            $toStatus = $lockedOrder->status === Order::STATUS_PENDING
                ? Order::STATUS_ACCEPTED
                : $lockedOrder->status;

            $lockedOrder->update([
                'rider_id' => $rider->id,
                'assigned_at' => now(),
                'status' => $toStatus,
            ]);

            OrderWorkflow::recordEvent(
                $lockedOrder,
                'rider_claimed_order',
                $rider,
                $fromStatus,
                $toStatus,
                'Rider self-claimed order from dispatch queue.',
                ['claimed_from_pool' => true]
            );
            RiderRealtimeBroadcaster::notifyRiderAndPool($rider->id, 'rider_order_claimed');
            CustomerOrderBroadcaster::notifyOrder($lockedOrder->customer_id, $lockedOrder->id, 'rider_order_claimed');

            return $lockedOrder->fresh()->load(['customer:id,name,phone', 'restaurant:id,name,phone']);
        });

        return response()->json([
            'message' => 'Order claimed successfully.',
            'order' => $this->serializeOrder($claimed),
        ]);
    }

    public function storeLocation(Request $request, Order $order): JsonResponse
    {
        $rider = $this->rider($request);
        $this->assertAssignedOrder($order, $rider);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ]);

        OrderWorkflow::recordEvent(
            $order,
            'rider_location_ping',
            $rider,
            $order->status,
            $order->status,
            'Rider location update',
            [
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'accuracy_meters' => isset($data['accuracy_meters']) ? (float) $data['accuracy_meters'] : null,
                'recorded_at' => now()->toIso8601String(),
            ]
        );
        RiderRealtimeBroadcaster::notifyRider($rider->id, 'rider_location_updated');
        CustomerOrderBroadcaster::notifyOrder($order->customer_id, $order->id, 'rider_location_updated', [
            'live_location' => [
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'accuracy_meters' => isset($data['accuracy_meters']) ? (float) $data['accuracy_meters'] : null,
                'recorded_at' => now()->toIso8601String(),
            ],
        ]);

        return response()->json([
            'message' => 'Location recorded.',
        ]);
    }

    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'delivery_mode' => $order->delivery_mode,
            'delivery_address' => $order->delivery_address,
            'delivery_floor' => $order->delivery_floor,
            'delivery_note' => $order->delivery_note,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'total' => (float) $order->total,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
            ] : null,
            'restaurant' => $order->restaurant ? [
                'id' => $order->restaurant->id,
                'name' => $order->restaurant->name,
                'phone' => $order->restaurant->phone,
            ] : null,
        ];
    }

    private function overviewPayload(User $rider): array
    {
        $activeStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
            Order::STATUS_OUT_FOR_DELIVERY,
        ];

        $activeOrders = Order::query()
            ->where('rider_id', $rider->id)
            ->whereIn('status', $activeStatuses)
            ->count();

        $completedToday = Order::query()
            ->where('rider_id', $rider->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereDate('updated_at', now()->toDateString())
            ->count();

        return [
            'rider' => [
                'id' => $rider->id,
                'name' => $rider->name,
                'email' => $rider->email,
                'phone' => $rider->phone,
                'is_active' => (bool) $rider->is_active,
            ],
            'active_orders_count' => (int) $activeOrders,
            'completed_today_count' => (int) $completedToday,
        ];
    }

    private function rider(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user || ! $user->isRider()) {
            abort(403, 'Rider access required.');
        }

        return $user;
    }

    private function assertAssignedOrder(Order $order, User $rider): void
    {
        if ((int) $order->rider_id !== (int) $rider->id) {
            abort(403, 'You are not assigned to this order.');
        }
    }
}
