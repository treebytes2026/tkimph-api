<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SupportNote;
use App\Models\User;
use App\Notifications\PartnerSystemNotification;
use App\Support\OrderWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class AdminRestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Restaurant::query()->with([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
        ]);

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $s = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                    ->orWhere('address', 'like', $s)
                    ->orWhere('phone', 'like', $s);
            });
        }

        $restaurants = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));
        $restaurants->getCollection()->transform(fn (Restaurant $restaurant) => $this->serializeRestaurant($restaurant));

        return response()->json($restaurants);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'business_category_id' => ['nullable', 'integer', 'exists:business_categories,id'],
            'cuisine_id' => ['nullable', 'integer', 'exists:cuisines,id'],
            'is_active' => ['sometimes', 'boolean'],
            'operating_status' => ['sometimes', Rule::in(Restaurant::OPERATING_STATUSES)],
            'operating_note' => ['nullable', 'string', 'max:1000'],
            'force_publicly_orderable' => ['sometimes', 'boolean'],
        ]);

        $owner = User::findOrFail($data['user_id']);
        if ($owner->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(422, 'Selected user must be a restaurant partner (restaurant_owner role).');
        }

        $data['is_active'] = $data['is_active'] ?? true;
        $data['operating_status'] = $data['operating_status'] ?? Restaurant::OPERATING_STATUS_OPEN;
        $restaurant = Restaurant::create($data);

        return response()->json($this->serializeRestaurant($restaurant->load([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
        ])), 201);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        return response()->json($this->serializeRestaurant($restaurant->load([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
            'supportNotes.admin:id,name,email',
        ]), true));
    }

    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'business_category_id' => ['nullable', 'integer', 'exists:business_categories,id'],
            'cuisine_id' => ['nullable', 'integer', 'exists:cuisines,id'],
            'is_active' => ['sometimes', 'boolean'],
            'operating_status' => ['sometimes', Rule::in(Restaurant::OPERATING_STATUSES)],
            'operating_note' => ['nullable', 'string', 'max:1000'],
            'force_publicly_orderable' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['user_id'])) {
            $owner = User::findOrFail($data['user_id']);
            if ($owner->role !== User::ROLE_RESTAURANT_OWNER) {
                abort(422, 'Selected user must be a restaurant partner (restaurant_owner role).');
            }
        }

        $restaurant->update($data);

        return response()->json($this->serializeRestaurant($restaurant->fresh()->load([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
            'supportNotes.admin:id,name,email',
        ]), true));
    }

    public function destroy(Restaurant $restaurant): JsonResponse
    {
        $restaurant->delete();

        return response()->json(['message' => 'Restaurant deleted.']);
    }

    public function toggleActive(Restaurant $restaurant): JsonResponse
    {
        $restaurant->update(['is_active' => ! $restaurant->is_active]);

        return response()->json($this->serializeRestaurant($restaurant->fresh()->load([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
        ])));
    }

    public function updateOperatingStatus(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'operating_status' => ['required', Rule::in(Restaurant::OPERATING_STATUSES)],
            'operating_note' => ['required', 'string', 'max:1000'],
            'paused_until' => ['nullable', 'date'],
        ]);

        $restaurant->update([
            'operating_status' => $data['operating_status'],
            'operating_note' => $data['operating_note'],
            'paused_until' => $data['operating_status'] === Restaurant::OPERATING_STATUS_PAUSED
                ? $data['paused_until'] ?? null
                : null,
        ]);

        if ($restaurant->owner) {
            $restaurant->owner->notify(new PartnerSystemNotification(
                $data['operating_status'] === Restaurant::OPERATING_STATUS_SUSPENDED ? 'partner_suspended' : 'store_status_changed',
                'Admin changed your store status to '.str_replace('_', ' ', $data['operating_status']).'.',
                [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_name' => $restaurant->name,
                    'operating_status' => $data['operating_status'],
                    'operating_note' => $data['operating_note'],
                ]
            ));
        }

        return response()->json($this->serializeRestaurant($restaurant->fresh()->load([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
            'supportNotes.admin:id,name,email',
        ]), true));
    }

    public function setPublicOrderOverride(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'force_publicly_orderable' => ['required', 'boolean'],
        ]);

        $restaurant->update([
            'force_publicly_orderable' => $data['force_publicly_orderable'],
        ]);

        return response()->json($this->serializeRestaurant($restaurant->fresh()->load([
            'owner:id,name,email,role',
            'businessType:id,name,slug',
            'businessCategory:id,name',
            'cuisine:id,name',
            'menus.items',
            'supportNotes.admin:id,name,email',
        ]), true));
    }

    public function settlementSummary(Request $request, Restaurant $restaurant): JsonResponse
    {
        $query = $restaurant->orders()
            ->whereNotIn('status', [\App\Models\Order::STATUS_CANCELLED, \App\Models\Order::STATUS_FAILED]);

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
            'service_fees' => round((float) $orders->sum('service_fee'), 2),
            'delivery_fees' => round((float) $orders->sum('delivery_fee'), 2),
            'restaurant_net' => round((float) $orders->sum('restaurant_net'), 2),
            'pending_settlement_amount' => round((float) $orders->where('status', '!=', \App\Models\Order::STATUS_COMPLETED)->sum('restaurant_net'), 2),
        ]);
    }

    public function storeSupportNote(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'note_type' => ['required', Rule::in(SupportNote::TYPES)],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = OrderWorkflow::addSupportNote($restaurant->id, null, $request->user(), $data['note_type'], $data['body']);

        return response()->json($this->serializeSupportNote($note->load('admin:id,name,email')), 201);
    }

    /** Partners dropdown for forms */
    public function partners(): JsonResponse
    {
        $owners = User::query()
            ->where('role', User::ROLE_RESTAURANT_OWNER)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json($owners);
    }

    private function serializeRestaurant(Restaurant $r, bool $includeSupportNotes = false): array
    {
        $readiness = $r->readinessStatus();

        return [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'phone' => $r->phone,
            'address' => $r->address,
            'user_id' => $r->user_id,
            'business_type_id' => $r->business_type_id,
            'business_category_id' => $r->business_category_id,
            'cuisine_id' => $r->cuisine_id,
            'is_active' => (bool) $r->is_active,
            'operating_status' => $r->operating_status ?? Restaurant::OPERATING_STATUS_OPEN,
            'operating_note' => $r->operating_note,
            'paused_until' => $r->paused_until?->toIso8601String(),
            'publicly_orderable' => $r->isOperationallyAvailable(),
            'force_publicly_orderable' => (bool) $r->force_publicly_orderable,
            'readiness_status' => $readiness['status'],
            'readiness_checks' => $readiness['checks'],
            'business_type' => $r->businessType ? [
                'id' => $r->businessType->id,
                'name' => $r->businessType->name,
                'slug' => $r->businessType->slug,
            ] : null,
            'business_category' => $r->businessCategory ? [
                'id' => $r->businessCategory->id,
                'name' => $r->businessCategory->name,
            ] : null,
            'cuisine' => $r->cuisine ? [
                'id' => $r->cuisine->id,
                'name' => $r->cuisine->name,
            ] : null,
            'owner' => $r->owner ? [
                'id' => $r->owner->id,
                'name' => $r->owner->name,
                'email' => $r->owner->email,
                'role' => $r->owner->role,
            ] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
            'support_notes' => $includeSupportNotes
                ? $r->supportNotes->map(fn (SupportNote $note) => $this->serializeSupportNote($note))->values()->all()
                : [],
        ];
    }

    private function serializeSupportNote(SupportNote $note): array
    {
        return [
            'id' => $note->id,
            'note_type' => $note->note_type,
            'body' => $note->body,
            'admin' => $note->admin ? [
                'id' => $note->admin->id,
                'name' => $note->admin->name,
                'email' => $note->admin->email,
            ] : null,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
