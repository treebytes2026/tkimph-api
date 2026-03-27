<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\OrderEvent;
use App\Models\SupportNote;
use App\Models\User;
use App\Notifications\PartnerSystemNotification;
use App\Support\OrderWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $hasRiderColumn = $this->hasRiderAssignmentColumns();
        $query = Order::query()
            ->with([
                'customer:id,name,phone',
                'restaurant:id,name',
                ...($hasRiderColumn ? ['rider:id,name,phone'] : []),
            ]);

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, Order::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', (int) $request->input('restaurant_id'));
        }

        if ($request->filled('rider_id') && $hasRiderColumn) {
            if ($request->string('rider_id')->toString() === 'unassigned') {
                $query->whereNull('rider_id');
            } else {
                $query->where('rider_id', (int) $request->input('rider_id'));
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('placed_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('placed_at', '<=', $request->string('date_to')->toString());
        }

        if ($request->filled('search')) {
            $s = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', $s)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $s))
                    ->orWhereHas('restaurant', fn ($r) => $r->where('name', 'like', $s));
            });
        }

        $orders = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));

        $stalledMinutes = AdminSetting::readInt('sla_stalled_minutes', 30);
        $threshold = now()->subMinutes($stalledMinutes);

        $orders->getCollection()->transform(fn (Order $order) => $this->serializeOrderRow($order, $threshold, $hasRiderColumn));

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $hasRiderColumn = $this->hasRiderAssignmentColumns();
        $order->load([
            'customer:id,name,email,phone',
            'restaurant:id,name',
            ...($hasRiderColumn ? ['rider:id,name,phone'] : []),
            'items',
            'adminNotes.admin:id,name,email',
            'events.actor:id,name,email,role',
            'supportNotes.admin:id,name,email',
        ]);

        $stalledMinutes = AdminSetting::readInt('sla_stalled_minutes', 30);
        $threshold = now()->subMinutes($stalledMinutes);

        return response()->json([
            ...$this->serializeOrderRow($order, $threshold, $hasRiderColumn),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
            'notes' => $order->adminNotes->map(fn (OrderAdminNote $note) => [
                'id' => $note->id,
                'note' => $note->note,
                'admin' => $note->admin ? [
                    'id' => $note->admin->id,
                    'name' => $note->admin->name,
                    'email' => $note->admin->email,
                ] : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->values()->all(),
            'timeline' => $order->events->map(fn (OrderEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'note' => $event->note,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                    'email' => $event->actor->email,
                    'role' => $event->actor->role,
                ] : null,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
            'support_notes' => $order->supportNotes->map(fn (SupportNote $note) => [
                'id' => $note->id,
                'note_type' => $note->note_type,
                'body' => $note->body,
                'admin' => $note->admin ? [
                    'id' => $note->admin->id,
                    'name' => $note->admin->name,
                    'email' => $note->admin->email,
                ] : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'cancellation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (AdminSetting::readBool('order_transition_guardrails', true)) {
            OrderWorkflow::assertTransition($order->status, $data['status']);
        }

        $fromStatus = $order->status;
        $isCancellationLike = in_array($data['status'], [Order::STATUS_CANCELLED, Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE], true);
        $reason = $data['cancellation_reason'] ?? $data['note'] ?? null;

        $order->update([
            'status' => $data['status'],
            'cancelled_by_role' => $isCancellationLike ? $request->user()->role : null,
            'cancellation_reason' => $isCancellationLike ? $reason : null,
            'cancelled_at' => $isCancellationLike ? now() : null,
        ]);

        if ($isCancellationLike) {
            OrderWorkflow::syncRefundStatusForException($order, $reason);
        }

        if (! empty($data['note'])) {
            $this->storeSystemNote($order, $request->user(), 'Status update: '.$data['note']);
        }

        OrderWorkflow::recordEvent(
            $order,
            $isCancellationLike ? 'admin_exception_status_change' : 'admin_status_change',
            $request->user(),
            $fromStatus,
            $data['status'],
            $reason
        );

        if ($order->restaurant?->owner) {
            $order->restaurant->owner->notify(new PartnerSystemNotification(
                $isCancellationLike ? 'order_cancelled' : 'order_status_changed',
                'Admin updated order '.$order->order_number.' to '.str_replace('_', ' ', $data['status']).'.',
                [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $data['status'],
                    'cancellation_reason' => $reason,
                ]
            ));
        }

        return response()->json([
            'message' => 'Order status updated.',
            'order' => $this->show($order->fresh())->getData(true),
        ]);
    }

    public function assignRider(Request $request, Order $order): JsonResponse
    {
        if (! $this->hasRiderAssignmentColumns()) {
            abort(503, 'Rider assignment is unavailable until migrations are applied.');
        }

        $data = $request->validate([
            'rider_id' => ['nullable', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $riderId = $data['rider_id'] ?? null;
        if ($riderId !== null) {
            $rider = User::query()->whereKey($riderId)->first();
            if (! $rider || ! $rider->isRider() || ! $rider->is_active) {
                abort(422, 'Selected rider is not available.');
            }
        }

        $order->update([
            'rider_id' => $riderId,
            'assigned_at' => $riderId ? now() : null,
        ]);

        if (! empty($data['note'])) {
            $this->storeSystemNote($order, $request->user(), 'Rider assignment: '.$data['note']);
        }

        OrderWorkflow::recordEvent(
            $order,
            $riderId ? 'rider_assigned' : 'rider_unassigned',
            $request->user(),
            $order->status,
            $order->status,
            $data['note'] ?? null,
            ['rider_id' => $riderId]
        );

        return response()->json([
            'message' => 'Rider assignment updated.',
            'order' => $this->show($order->fresh())->getData(true),
        ]);
    }

    public function storeNote(Request $request, Order $order): JsonResponse
    {
        if (! $this->hasOrderAdminNotesTable()) {
            abort(503, 'Order notes are unavailable until migrations are applied.');
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $note = $this->storeSystemNote($order, $request->user(), $data['note']);

        return response()->json([
            'message' => 'Note added.',
            'note' => [
                'id' => $note->id,
                'note' => $note->note,
                'admin' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ],
                'created_at' => $note->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function storeSupportNote(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'note_type' => ['required', Rule::in(SupportNote::TYPES)],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = OrderWorkflow::addSupportNote($order->restaurant_id, $order->id, $request->user(), $data['note_type'], $data['body']);

        return response()->json([
            'id' => $note->id,
            'note_type' => $note->note_type,
            'body' => $note->body,
            'admin' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
            'created_at' => $note->created_at?->toIso8601String(),
        ], 201);
    }

    public function summary(): JsonResponse
    {
        $slaMinutes = AdminSetting::readInt('sla_stalled_minutes', 30);
        $threshold = now()->subMinutes($slaMinutes);
        $activeStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_PREPARING,
            Order::STATUS_OUT_FOR_DELIVERY,
        ];

        $base = Order::query();
        $active = Order::query()->whereIn('status', $activeStatuses);
        $settleable = Order::query()->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED]);

        return response()->json([
            'total_orders' => (int) $base->count(),
            'pending' => (int) Order::query()->where('status', Order::STATUS_PENDING)->count(),
            'accepted' => (int) Order::query()->where('status', Order::STATUS_ACCEPTED)->count(),
            'preparing' => (int) Order::query()->where('status', Order::STATUS_PREPARING)->count(),
            'out_for_delivery' => (int) Order::query()->where('status', Order::STATUS_OUT_FOR_DELIVERY)->count(),
            'completed' => (int) Order::query()->where('status', Order::STATUS_COMPLETED)->count(),
            'failed' => (int) Order::query()->where('status', Order::STATUS_FAILED)->count(),
            'undeliverable' => (int) Order::query()->where('status', Order::STATUS_UNDELIVERABLE)->count(),
            'unassigned_active_orders' => $this->hasRiderAssignmentColumns()
                ? (int) (clone $active)->whereNull('rider_id')->count()
                : 0,
            'stalled_orders' => (int) (clone $active)->where('updated_at', '<', $threshold)->count(),
            'active_riders' => (int) User::query()->where('role', User::ROLE_RIDER)->where('is_active', true)->count(),
            'gross_sales' => round((float) $settleable->sum('gross_sales'), 2),
            'restaurant_net' => round((float) $settleable->sum('restaurant_net'), 2),
            'sla_stalled_minutes' => $slaMinutes,
        ]);
    }

    private function serializeOrderRow(Order $order, Carbon $threshold, bool $hasRiderColumn): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'gross_sales' => (float) ($order->gross_sales ?? $order->subtotal),
            'service_fee' => (float) $order->service_fee,
            'delivery_fee' => (float) $order->delivery_fee,
            'restaurant_net' => (float) ($order->restaurant_net ?? max(0, (float) $order->subtotal - (float) $order->service_fee)),
            'total' => (float) $order->total,
            'delivery_mode' => $order->delivery_mode,
            'delivery_address' => $order->delivery_address,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'assigned_at' => $order->assigned_at?->toIso8601String(),
            'cancelled_by_role' => $order->cancelled_by_role,
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'is_stalled' => in_array($order->status, [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_PREPARING,
                Order::STATUS_OUT_FOR_DELIVERY,
            ], true) && $order->updated_at !== null && $order->updated_at->lt($threshold),
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email ?? null,
            ] : null,
            'restaurant' => $order->restaurant ? [
                'id' => $order->restaurant->id,
                'name' => $order->restaurant->name,
            ] : null,
            'rider' => $hasRiderColumn && $order->rider ? [
                'id' => $order->rider->id,
                'name' => $order->rider->name,
                'phone' => $order->rider->phone,
            ] : null,
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    private function storeSystemNote(Order $order, User $admin, string $note): ?OrderAdminNote
    {
        if (! $this->hasOrderAdminNotesTable()) {
            return null;
        }

        return $order->adminNotes()->create([
            'admin_id' => $admin->id,
            'note' => $note,
        ]);
    }

    private function hasOrderAdminNotesTable(): bool
    {
        return Schema::hasTable('order_admin_notes');
    }

    private function hasRiderAssignmentColumns(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'rider_id')
            && Schema::hasColumn('orders', 'assigned_at');
    }
}
