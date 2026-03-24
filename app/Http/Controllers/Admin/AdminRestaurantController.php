<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class AdminRestaurantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Restaurant::query()->with('owner:id,name,email,role');

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
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $owner = User::findOrFail($data['user_id']);
        if ($owner->role !== User::ROLE_RESTAURANT_OWNER) {
            abort(422, 'Selected user must be a restaurant partner (restaurant_owner role).');
        }

        $data['is_active'] = $data['is_active'] ?? true;
        $restaurant = Restaurant::create($data);

        return response()->json($this->serializeRestaurant($restaurant->load('owner:id,name,email,role')), 201);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        return response()->json($this->serializeRestaurant($restaurant->load('owner:id,name,email,role')));
    }

    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['user_id'])) {
            $owner = User::findOrFail($data['user_id']);
            if ($owner->role !== User::ROLE_RESTAURANT_OWNER) {
                abort(422, 'Selected user must be a restaurant partner (restaurant_owner role).');
            }
        }

        $restaurant->update($data);

        return response()->json($this->serializeRestaurant($restaurant->fresh()->load('owner:id,name,email,role')));
    }

    public function destroy(Restaurant $restaurant): JsonResponse
    {
        $restaurant->delete();

        return response()->json(['message' => 'Restaurant deleted.']);
    }

    public function toggleActive(Restaurant $restaurant): JsonResponse
    {
        $restaurant->update(['is_active' => ! $restaurant->is_active]);

        return response()->json($this->serializeRestaurant($restaurant->fresh()->load('owner:id,name,email,role')));
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

    private function serializeRestaurant(Restaurant $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'phone' => $r->phone,
            'address' => $r->address,
            'user_id' => $r->user_id,
            'is_active' => (bool) $r->is_active,
            'owner' => $r->owner ? [
                'id' => $r->owner->id,
                'name' => $r->owner->name,
                'email' => $r->owner->email,
                'role' => $r->owner->role,
            ] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
