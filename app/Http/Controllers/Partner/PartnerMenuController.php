<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerMenuController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        $menus = $restaurant->menus()
            ->withCount(['items'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $menus]);
    }

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? ((int) ($restaurant->menus()->max('sort_order') ?? -1) + 1);
        $data['is_active'] = $data['is_active'] ?? true;

        $menu = $restaurant->menus()->create($data);

        return response()->json($menu->loadCount(['items']), 201);
    }

    public function show(Request $request, Restaurant $restaurant, Menu $menu): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id, 404);

        $menu->load([
            'items' => function ($q) {
                $q->orderBy('sort_order')->orderBy('name');
            },
            'items.menuCategory',
        ]);

        return response()->json($menu);
    }

    public function update(Request $request, Restaurant $restaurant, Menu $menu): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $menu->update($data);

        return response()->json($menu->fresh()->loadCount(['items']));
    }

    public function destroy(Request $request, Restaurant $restaurant, Menu $menu): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id, 404);

        $menu->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function authorizePartnerRestaurant(Request $request, Restaurant $restaurant): void
    {
        $user = $request->user();
        abort_unless(
            $user && $user->role === User::ROLE_RESTAURANT_OWNER && $restaurant->user_id === $user->id,
            403,
            'You do not manage this restaurant.'
        );
    }
}
