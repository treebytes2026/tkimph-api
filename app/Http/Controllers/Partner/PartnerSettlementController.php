<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Concerns\InteractsWithSettlementSettings;
use App\Http\Controllers\Controller;
use App\Models\RestaurantSettlement;
use App\Models\User;
use App\Notifications\AdminSystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PartnerSettlementController extends Controller
{
    use InteractsWithSettlementSettings;

    public function index(Request $request): JsonResponse
    {
        $this->ensureSettlementsEnabled();

        $partner = $this->partner($request);
        $restaurantIds = $partner->restaurants()->pluck('id');

        $query = RestaurantSettlement::query()
            ->with(['restaurant:id,name'])
            ->whereIn('restaurant_id', $restaurantIds)
            ->orderByDesc('period_to')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $rows = $query->paginate($request->integer('per_page', 20));
        $rows->getCollection()->transform(fn (RestaurantSettlement $row) => $this->serializeSettlement($row));

        return response()
            ->json($rows)
            ->header('Cache-Control', 'private, no-store, must-revalidate');
    }

    public function submitPaymentProof(Request $request, RestaurantSettlement $settlement): JsonResponse
    {
        $this->ensureSettlementsEnabled();

        $partner = $this->partner($request);
        $restaurantIds = $partner->restaurants()->pluck('id');
        abort_unless($restaurantIds->contains($settlement->restaurant_id), 403, 'You do not manage this settlement.');

        $data = $request->validate([
            'partner_reference_number' => ['required', 'string', 'max:120'],
            'partner_payment_note' => ['nullable', 'string', 'max:2000'],
            'payment_proof' => ['required', 'file', 'max:5120', 'mimes:jpeg,jpg,png,webp,pdf'],
        ]);

        $file = $request->file('payment_proof');
        $path = $file->store('settlements/proofs', 'public');

        if ($settlement->payment_proof_path && $settlement->payment_proof_path !== $path) {
            Storage::disk('public')->delete($settlement->payment_proof_path);
        }

        $settlement->update([
            'partner_reference_number' => $data['partner_reference_number'],
            'partner_payment_note' => $data['partner_payment_note'] ?? null,
            'payment_proof_path' => $path,
            'payment_submitted_at' => now(),
            'payment_submitted_by_partner_id' => $partner->id,
        ]);

        $settlement->loadMissing('restaurant:id,name');
        User::query()
            ->admins()
            ->each(fn (User $admin) => $admin->notify(new AdminSystemNotification(
                'settlement_payment_proof_submitted',
                'Payment proof submitted by '.$partner->name.' for '.$settlement->restaurant?->name.'.',
                [
                    'settlement_id' => $settlement->id,
                    'restaurant_id' => $settlement->restaurant_id,
                    'restaurant_name' => $settlement->restaurant?->name,
                    'partner_id' => $partner->id,
                    'partner_name' => $partner->name,
                    'partner_email' => $partner->email,
                    'partner_reference_number' => $settlement->partner_reference_number,
                    'payment_proof_url' => Storage::disk('public')->url($path),
                    'period_from' => $settlement->period_from?->toDateString(),
                    'period_to' => $settlement->period_to?->toDateString(),
                ]
            )));

        return response()->json([
            'message' => 'Payment proof submitted to admin.',
            'settlement' => $this->serializeSettlement($settlement->fresh()->load('restaurant:id,name')),
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
            'status' => $row->status,
            'platform_revenue' => (float) $row->platform_revenue,
            'partner_reference_number' => $row->partner_reference_number,
            'partner_payment_note' => $row->partner_payment_note,
            'payment_proof_path' => $row->payment_proof_path,
            'payment_proof_url' => $row->payment_proof_path ? Storage::disk('public')->url($row->payment_proof_path) : null,
            'payment_submitted_at' => $row->payment_submitted_at?->toIso8601String(),
            'last_overdue_notified_at' => $row->last_overdue_notified_at?->toIso8601String(),
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
