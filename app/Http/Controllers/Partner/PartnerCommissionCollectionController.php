<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\CommissionCollection;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use App\Support\CommissionCollectionMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PartnerCommissionCollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $partner = $this->partner($request);
        $restaurantIds = $partner->restaurants()->pluck('id');

        $query = CommissionCollection::query()
            ->with(['restaurant:id,name'])
            ->whereIn('restaurant_id', $restaurantIds)
            ->orderByDesc('period_to')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if (in_array($status, CommissionCollection::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        $rows = $query->paginate($request->integer('per_page', 20));
        $rows->getCollection()->transform(fn (CommissionCollection $row) => $this->serializeCollection($row));

        return response()->json([
            'data' => $rows->items(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'per_page' => $rows->perPage(),
            'total' => $rows->total(),
            'payment_details' => [
                'gcash_name' => AdminSetting::read('commission_payment_gcash_name', ''),
                'gcash_number' => AdminSetting::read('commission_payment_gcash_number', ''),
            ],
        ])->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function submitPaymentProof(Request $request, CommissionCollection $collection): JsonResponse
    {
        CommissionCollectionMonitor::processOverdueCollections();

        $partner = $this->partner($request);
        $restaurantIds = $partner->restaurants()->pluck('id');
        abort_unless($restaurantIds->contains($collection->restaurant_id), 403, 'You do not manage this commission record.');

        $data = $request->validate([
            'partner_payment_method' => ['required', Rule::in(CommissionCollection::PAYMENT_METHODS)],
            'partner_reference_number' => ['nullable', 'string', 'max:120'],
            'partner_payment_note' => ['nullable', 'string', 'max:2000'],
            'payment_proof' => ['required', 'file', 'max:5120', 'mimes:jpeg,jpg,png,webp,pdf'],
        ]);

        $file = $request->file('payment_proof');
        $path = $file->store('commission-collections/proofs', 'public');

        if ($collection->payment_proof_path && $collection->payment_proof_path !== $path) {
            Storage::disk('public')->delete($collection->payment_proof_path);
        }

        $collection->update([
            'partner_payment_method' => $data['partner_payment_method'],
            'partner_reference_number' => $data['partner_reference_number'] ?? null,
            'partner_payment_note' => $data['partner_payment_note'] ?? null,
            'payment_proof_path' => $path,
            'payment_submitted_at' => now(),
            'payment_submitted_by_partner_id' => $partner->id,
        ]);

        $collection->loadMissing('restaurant:id,name');

        User::query()
            ->admins()
            ->each(fn (User $admin) => $admin->notify(new AdminSystemNotification(
                'commission_payment_proof_submitted',
                'Commission payment proof submitted by '.$partner->name.' for '.$collection->restaurant?->name.'.',
                [
                    'collection_id' => $collection->id,
                    'restaurant_id' => $collection->restaurant_id,
                    'restaurant_name' => $collection->restaurant?->name,
                    'partner_id' => $partner->id,
                    'partner_name' => $partner->name,
                    'partner_email' => $partner->email,
                    'partner_payment_method' => $collection->partner_payment_method,
                    'partner_reference_number' => $collection->partner_reference_number,
                    'payment_proof_url' => Storage::disk('public')->url($path),
                    'period_from' => $collection->period_from?->toDateString(),
                    'period_to' => $collection->period_to?->toDateString(),
                    'commission_amount' => (float) $collection->commission_amount,
                ]
            )));

        return response()->json([
            'message' => 'Payment proof submitted to admin.',
            'collection' => $this->serializeCollection($collection->fresh()->load('restaurant:id,name')),
        ]);
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
            'order_count' => (int) $row->order_count,
            'gross_sales' => (float) $row->gross_sales,
            'commission_amount' => (float) $row->commission_amount,
            'restaurant_net' => (float) $row->restaurant_net,
            'status' => $row->status,
            'partner_payment_method' => $row->partner_payment_method,
            'partner_reference_number' => $row->partner_reference_number,
            'partner_payment_note' => $row->partner_payment_note,
            'payment_proof_path' => $row->payment_proof_path,
            'payment_proof_url' => $row->payment_proof_path ? Storage::disk('public')->url($row->payment_proof_path) : null,
            'payment_submitted_at' => $row->payment_submitted_at?->toIso8601String(),
            'collection_reference' => $row->collection_reference,
            'notes' => $row->notes,
            'received_at' => $row->received_at?->toIso8601String(),
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
