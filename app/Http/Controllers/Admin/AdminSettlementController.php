<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithSettlementSettings;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantSettlement;
use App\Notifications\PartnerSystemNotification;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminSettlementController extends Controller
{
    use InteractsWithSettlementSettings;

    public function index(Request $request): JsonResponse
    {
        $this->ensureSettlementsEnabled();

        $query = RestaurantSettlement::query()
            ->with(['restaurant:id,name', 'createdByAdmin:id,name,email', 'settledByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])
            ->orderByDesc('period_to')
            ->orderByDesc('id');

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', (int) $request->input('restaurant_id'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, RestaurantSettlement::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('period_from', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('period_to', '<=', $request->string('date_to')->toString());
        }

        $rows = $query->paginate($request->integer('per_page', 20));
        $rows->getCollection()->transform(fn (RestaurantSettlement $row) => $this->serializeSettlement($row));

        return response()
            ->json($rows)
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSettlementsEnabled();

        $data = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $periodFrom = $data['period_from'];
        $periodTo = $data['period_to'];
        $restaurantId = (int) $data['restaurant_id'];
        $dueDate = Carbon::parse($periodTo)->addDays(3)->toDateString();

        $existing = RestaurantSettlement::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('period_from', $periodFrom)
            ->whereDate('period_to', $periodTo)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Settlement period already exists.',
                'settlement' => $this->serializeSettlement($existing->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'settledByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])),
            ]);
        }

        $orders = Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereDate('placed_at', '>=', $periodFrom)
            ->whereDate('placed_at', '<=', $periodTo)
            ->get();

        $grossSales = (float) $orders->sum('gross_sales');
        $serviceFees = (float) $orders->sum('service_fee');
        $deliveryFees = (float) $orders->sum('delivery_fee');
        $restaurantNet = (float) $orders->sum('restaurant_net');

        $row = RestaurantSettlement::query()->create([
            'restaurant_id' => $restaurantId,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'due_date' => $dueDate,
            'order_count' => $orders->count(),
            'gross_sales' => round($grossSales, 2),
            'service_fees' => round($serviceFees, 2),
            'delivery_fees' => round($deliveryFees, 2),
            'restaurant_net' => round($restaurantNet, 2),
            'platform_revenue' => round($serviceFees + $deliveryFees, 2),
            'status' => RestaurantSettlement::STATUS_PENDING,
            'created_by_admin_id' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ])->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'settledByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email']);

        $restaurant = Restaurant::query()->with('owner:id,name,email')->find($restaurantId);
        if ($restaurant?->owner) {
            $restaurant->owner->notify(new PartnerSystemNotification(
                'settlement_generated',
                'A new settlement is ready for '.$restaurant->name.' and is due on '.$dueDate.'.',
                [
                    'settlement_id' => $row->id,
                    'restaurant_id' => $restaurantId,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'due_date' => $dueDate,
                    'amount_due' => (float) $row->platform_revenue,
                ]
            ));
        }

        return response()->json([
            'message' => 'Settlement generated.',
            'settlement' => $this->serializeSettlement($row),
        ], 201);
    }

    public function markSettled(Request $request, RestaurantSettlement $settlement): JsonResponse
    {
        $this->ensureSettlementsEnabled();

        $data = $request->validate([
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(RestaurantSettlement::STATUSES)],
        ]);

        $nextStatus = $data['status'] ?? RestaurantSettlement::STATUS_SETTLED;

        $settlement->update([
            'status' => $nextStatus,
            'reference_number' => $data['reference_number'] ?? $settlement->reference_number,
            'notes' => $data['notes'] ?? $settlement->notes,
            'settled_at' => $nextStatus === RestaurantSettlement::STATUS_SETTLED ? now() : null,
            'settled_by_admin_id' => $nextStatus === RestaurantSettlement::STATUS_SETTLED ? $request->user()->id : null,
        ]);

        $settlement->loadMissing('restaurant.owner:id,name,email');
        if ($settlement->restaurant?->owner) {
            $settlement->restaurant->owner->notify(new PartnerSystemNotification(
                $nextStatus === RestaurantSettlement::STATUS_SETTLED ? 'settlement_settled' : 'settlement_reopened',
                $nextStatus === RestaurantSettlement::STATUS_SETTLED
                    ? 'Settlement marked as settled for period '.$settlement->period_from?->toDateString().' to '.$settlement->period_to?->toDateString().'.'
                    : 'Settlement moved back to pending for period '.$settlement->period_from?->toDateString().' to '.$settlement->period_to?->toDateString().'.',
                [
                    'settlement_id' => $settlement->id,
                    'restaurant_id' => $settlement->restaurant_id,
                    'period_from' => $settlement->period_from?->toDateString(),
                    'period_to' => $settlement->period_to?->toDateString(),
                    'status' => $nextStatus,
                    'reference_number' => $settlement->reference_number,
                ]
            ));
        }

        return response()->json([
            'message' => $nextStatus === RestaurantSettlement::STATUS_SETTLED ? 'Settlement marked as settled.' : 'Settlement moved to pending.',
            'settlement' => $this->serializeSettlement($settlement->fresh()->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'settledByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])),
        ]);
    }

    public function enforceOverdue(Request $request, RestaurantSettlement $settlement): JsonResponse
    {
        $this->ensureSettlementsEnabled();

        $data = $request->validate([
            'action' => ['required', Rule::in(['notify', 'pause', 'suspend'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $settlement->loadMissing('restaurant.owner:id,name,email');

        if ($settlement->status !== RestaurantSettlement::STATUS_PENDING) {
            abort(422, 'Only pending settlements can use overdue actions.');
        }

        $dueDate = $settlement->due_date?->toDateString();
        $note = $data['note'] ?? null;
        $action = $data['action'];

        if ($action === 'pause' || $action === 'suspend') {
            $settlement->restaurant?->update([
                'operating_status' => $action === 'pause' ? Restaurant::OPERATING_STATUS_PAUSED : Restaurant::OPERATING_STATUS_SUSPENDED,
                'operating_note' => $note ?: 'Settlement overdue action applied by admin.',
            ]);
        }

        $settlement->update(['last_overdue_notified_at' => now()]);

        if ($settlement->restaurant?->owner) {
            $message = match ($action) {
                'notify' => 'Reminder: your settlement is overdue'.($dueDate ? ' since '.$dueDate : '').'.',
                'pause' => 'Your restaurant was paused due to overdue settlement. Contact admin support.',
                default => 'Your restaurant was suspended due to overdue settlement. Contact admin support.',
            };

            $settlement->restaurant->owner->notify(new PartnerSystemNotification(
                'settlement_overdue_'.$action,
                $message,
                [
                    'settlement_id' => $settlement->id,
                    'restaurant_id' => $settlement->restaurant_id,
                    'period_from' => $settlement->period_from?->toDateString(),
                    'period_to' => $settlement->period_to?->toDateString(),
                    'due_date' => $dueDate,
                    'action' => $action,
                    'note' => $note,
                ]
            ));
        }

        return response()->json([
            'message' => 'Overdue action sent.',
            'settlement' => $this->serializeSettlement($settlement->fresh()->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'settledByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])),
        ]);
    }

    private function serializeSettlement(RestaurantSettlement $row): array
    {
        return [
            'id' => $row->id,
            'restaurant_id' => $row->restaurant_id,
            'restaurant' => $row->restaurant ? [
                'id' => $row->restaurant->id,
                'name' => $row->restaurant->name,
            ] : null,
            'period_from' => $row->period_from?->toDateString(),
            'period_to' => $row->period_to?->toDateString(),
            'due_date' => $row->due_date?->toDateString(),
            'is_overdue' => $row->status === RestaurantSettlement::STATUS_PENDING
                && $row->due_date !== null
                && $row->due_date->lt(now()->startOfDay()),
            'overdue_days' => $row->status === RestaurantSettlement::STATUS_PENDING
                && $row->due_date !== null
                && $row->due_date->lt(now()->startOfDay())
                ? $row->due_date->diffInDays(now()->startOfDay())
                : 0,
            'order_count' => (int) $row->order_count,
            'gross_sales' => (float) $row->gross_sales,
            'service_fees' => (float) $row->service_fees,
            'delivery_fees' => (float) $row->delivery_fees,
            'restaurant_net' => (float) $row->restaurant_net,
            'platform_revenue' => (float) $row->platform_revenue,
            'status' => $row->status,
            'reference_number' => $row->reference_number,
            'partner_reference_number' => $row->partner_reference_number,
            'partner_payment_note' => $row->partner_payment_note,
            'payment_proof_path' => $row->payment_proof_path,
            'payment_proof_url' => $row->payment_proof_path ? Storage::disk('public')->url($row->payment_proof_path) : null,
            'payment_submitted_at' => $row->payment_submitted_at?->toIso8601String(),
            'payment_submitted_by_partner' => $row->paymentSubmittedByPartner ? [
                'id' => $row->paymentSubmittedByPartner->id,
                'name' => $row->paymentSubmittedByPartner->name,
                'email' => $row->paymentSubmittedByPartner->email,
            ] : null,
            'notes' => $row->notes,
            'settled_at' => $row->settled_at?->toIso8601String(),
            'last_overdue_notified_at' => $row->last_overdue_notified_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'created_by_admin' => $row->createdByAdmin ? [
                'id' => $row->createdByAdmin->id,
                'name' => $row->createdByAdmin->name,
                'email' => $row->createdByAdmin->email,
            ] : null,
            'settled_by_admin' => $row->settledByAdmin ? [
                'id' => $row->settledByAdmin->id,
                'name' => $row->settledByAdmin->name,
                'email' => $row->settledByAdmin->email,
            ] : null,
        ];
    }
}
