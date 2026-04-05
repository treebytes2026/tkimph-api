<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionCollection;
use App\Models\Order;
use App\Models\Restaurant;
use App\Notifications\PartnerSystemNotification;
use App\Support\CommissionCollectionMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminCommissionCollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $query = CommissionCollection::query()
            ->with(['restaurant:id,name', 'createdByAdmin:id,name,email', 'receivedByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])
            ->orderByDesc('period_to')
            ->orderByDesc('id');

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', (int) $request->input('restaurant_id'));
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, CommissionCollection::STATUSES, true)) {
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
        $rows->getCollection()->transform(fn (CommissionCollection $row) => $this->serializeCollection($row));

        return response()
            ->json($rows)
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = $this->firstOrCreateCollection(
            restaurantId: (int) $data['restaurant_id'],
            periodFrom: $data['period_from'],
            periodTo: $data['period_to'],
            adminId: $request->user()->id,
            notes: $data['notes'] ?? null,
        );

        return response()->json([
            'message' => $row->wasRecentlyCreated ? 'Commission collection created.' : 'Commission collection already exists.',
            'collection' => $this->serializeCollection($row->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'receivedByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])),
        ], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function storeBulk(Request $request): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $data = $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $collections = [];
        $restaurants = Restaurant::query()
            ->with('owner:id,name,email')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'user_id']);

        foreach ($restaurants as $restaurant) {
            $orders = $this->completedOrdersForPeriod($restaurant->id, $data['period_from'], $data['period_to']);
            if ($orders->isEmpty()) {
                continue;
            }

            $collections[] = $this->firstOrCreateCollection(
                restaurantId: $restaurant->id,
                periodFrom: $data['period_from'],
                periodTo: $data['period_to'],
                adminId: $request->user()->id,
                notes: $data['notes'] ?? null,
                preloadedRestaurant: $restaurant,
            );
        }

        return response()->json([
            'message' => count($collections) > 0 ? 'Commission collections generated.' : 'No completed-order commissions found for that period.',
            'collections' => array_map(
                fn (CommissionCollection $row) => $this->serializeCollection($row->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'receivedByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])),
                $collections
            ),
        ], count($collections) > 0 ? 201 : 200);
    }

    public function markReceived(Request $request, CommissionCollection $collection): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $data = $request->validate([
            'status' => ['nullable', Rule::in(CommissionCollection::STATUSES)],
            'collection_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $nextStatus = $data['status'] ?? CommissionCollection::STATUS_RECEIVED;
        $received = $nextStatus === CommissionCollection::STATUS_RECEIVED;

        $collection->update([
            'status' => $nextStatus,
            'collection_reference' => $data['collection_reference'] ?? $collection->collection_reference,
            'notes' => $data['notes'] ?? $collection->notes,
            'received_at' => $received ? now() : null,
            'received_by_admin_id' => $received ? $request->user()->id : null,
        ]);

        $collection->loadMissing('restaurant.owner:id,name,email');
        if ($collection->restaurant?->owner) {
            $collection->restaurant->owner->notify(new PartnerSystemNotification(
                $received ? 'commission_collection_received' : 'commission_collection_reopened',
                $received
                    ? 'Your platform commission payment was marked as received for '.$collection->period_from?->toDateString().' to '.$collection->period_to?->toDateString().'.'
                    : 'Your platform commission collection was moved back to pending for '.$collection->period_from?->toDateString().' to '.$collection->period_to?->toDateString().'.',
                [
                    'collection_id' => $collection->id,
                    'restaurant_id' => $collection->restaurant_id,
                    'period_from' => $collection->period_from?->toDateString(),
                    'period_to' => $collection->period_to?->toDateString(),
                    'commission_amount' => (float) $collection->commission_amount,
                    'status' => $nextStatus,
                    'collection_reference' => $collection->collection_reference,
                ]
            ));
        }

        return response()->json([
            'message' => $received ? 'Commission marked as received.' : 'Commission moved back to pending.',
            'collection' => $this->serializeCollection($collection->fresh()->load(['restaurant:id,name', 'createdByAdmin:id,name,email', 'receivedByAdmin:id,name,email', 'paymentSubmittedByPartner:id,name,email'])),
        ]);
    }

    private function firstOrCreateCollection(
        int $restaurantId,
        string $periodFrom,
        string $periodTo,
        int $adminId,
        ?string $notes = null,
        ?Restaurant $preloadedRestaurant = null,
    ): CommissionCollection {
        $existing = CommissionCollection::query()
            ->where('restaurant_id', $restaurantId)
            ->whereDate('period_from', $periodFrom)
            ->whereDate('period_to', $periodTo)
            ->first();

        if ($existing) {
            return $existing;
        }

        $orders = $this->completedOrdersForPeriod($restaurantId, $periodFrom, $periodTo);

        $row = CommissionCollection::query()->create([
            'restaurant_id' => $restaurantId,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'due_date' => Carbon::parse($periodTo)->addDays(3)->toDateString(),
            'order_count' => $orders->count(),
            'gross_sales' => round((float) $orders->sum('gross_sales'), 2),
            'commission_amount' => round((float) $orders->sum('service_fee'), 2),
            'restaurant_net' => round((float) $orders->sum('restaurant_net'), 2),
            'status' => CommissionCollection::STATUS_PENDING,
            'created_by_admin_id' => $adminId,
            'notes' => $notes,
        ]);

        $restaurant = $preloadedRestaurant ?? Restaurant::query()->with('owner:id,name,email')->find($restaurantId);
        if ($restaurant?->owner) {
            $restaurant->owner->notify(new PartnerSystemNotification(
                'commission_collection_created',
                'A commission collection has been recorded for '.$restaurant->name.' covering '.Carbon::parse($periodFrom)->toDateString().' to '.Carbon::parse($periodTo)->toDateString().'.',
                [
                    'collection_id' => $row->id,
                    'restaurant_id' => $restaurantId,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'commission_amount' => (float) $row->commission_amount,
                    'order_count' => (int) $row->order_count,
                ]
            ));
        }

        return $row;
    }

    private function completedOrdersForPeriod(int $restaurantId, string $periodFrom, string $periodTo)
    {
        return Order::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereDate('placed_at', '>=', $periodFrom)
            ->whereDate('placed_at', '<=', $periodTo)
            ->get();
    }

    private function serializeCollection(CommissionCollection $row): array
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
            'is_overdue' => $row->status === CommissionCollection::STATUS_PENDING
                && $row->due_date !== null
                && $row->due_date->lt(now()->startOfDay()),
            'overdue_days' => $row->status === CommissionCollection::STATUS_PENDING
                && $row->due_date !== null
                && $row->due_date->lt(now()->startOfDay())
                ? $row->due_date->diffInDays(now()->startOfDay())
                : 0,
            'order_count' => (int) $row->order_count,
            'gross_sales' => (float) $row->gross_sales,
            'commission_amount' => (float) $row->commission_amount,
            'restaurant_net' => (float) $row->restaurant_net,
            'status' => $row->status,
            'collection_reference' => $row->collection_reference,
            'partner_payment_method' => $row->partner_payment_method,
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
            'received_at' => $row->received_at?->toIso8601String(),
            'last_overdue_notified_at' => $row->last_overdue_notified_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'created_by_admin' => $row->createdByAdmin ? [
                'id' => $row->createdByAdmin->id,
                'name' => $row->createdByAdmin->name,
                'email' => $row->createdByAdmin->email,
            ] : null,
            'received_by_admin' => $row->receivedByAdmin ? [
                'id' => $row->receivedByAdmin->id,
                'name' => $row->receivedByAdmin->name,
                'email' => $row->receivedByAdmin->email,
            ] : null,
        ];
    }
}
