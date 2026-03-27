<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRiderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->where('role', User::ROLE_RIDER);

        if ($request->filled('search')) {
            $s = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('phone', 'like', $s);
            });
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $riders = $query
            ->withCount([
                'assignedOrders as active_orders_count' => fn ($q) => $q->whereIn('status', [
                    Order::STATUS_PENDING,
                    Order::STATUS_ACCEPTED,
                    Order::STATUS_PREPARING,
                    Order::STATUS_OUT_FOR_DELIVERY,
                ]),
                'assignedOrders as completed_orders_count' => fn ($q) => $q->where('status', Order::STATUS_COMPLETED),
            ])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        $riders->getCollection()->transform(fn (User $rider) => $this->serializeRider($rider));

        return response()->json($riders);
    }

    public function show(User $rider): JsonResponse
    {
        $this->guardRider($rider);

        $rider->load([
            'assignedOrders' => fn ($q) => $q
                ->with(['customer:id,name,phone', 'restaurant:id,name'])
                ->latest('id')
                ->limit(10),
        ]);

        return response()->json([
            ...$this->serializeRider($rider),
            'recent_orders' => $rider->assignedOrders->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => (float) $order->total,
                'placed_at' => $order->placed_at?->toIso8601String(),
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => $order->customer->name,
                    'phone' => $order->customer->phone,
                ] : null,
                'restaurant' => $order->restaurant ? [
                    'id' => $order->restaurant->id,
                    'name' => $order->restaurant->name,
                ] : null,
            ])->values()->all(),
        ]);
    }

    public function setActive(Request $request, User $rider): JsonResponse
    {
        $this->guardRider($rider);
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $rider->update(['is_active' => $data['is_active']]);

        return response()->json($this->serializeRider($rider->fresh()));
    }

    private function guardRider(User $user): void
    {
        if (! $user->isRider()) {
            abort(404);
        }
    }

    private function serializeRider(User $rider): array
    {
        return [
            'id' => $rider->id,
            'name' => $rider->name,
            'email' => $rider->email,
            'phone' => $rider->phone,
            'address' => $rider->address,
            'is_active' => (bool) $rider->is_active,
            'active_orders_count' => (int) ($rider->active_orders_count ?? 0),
            'completed_orders_count' => (int) ($rider->completed_orders_count ?? 0),
            'created_at' => $rider->created_at?->toIso8601String(),
        ];
    }
}

