<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\MenuPricing;
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
            ->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json($menus);
    }

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'discount_enabled' => ['sometimes', 'boolean'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $data['sort_order'] = $data['sort_order'] ?? ((int) ($restaurant->menus()->max('sort_order') ?? -1) + 1);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['discount_enabled'] = $data['discount_enabled'] ?? false;
        $data['discount_percent'] = MenuPricing::normalizeDiscountPercent((float) ($data['discount_percent'] ?? 0));

        $menu = $restaurant->menus()->create($data);

        return response()->json($menu->loadCount(['items']), 201);
    }

    public function show(Request $request, Restaurant $restaurant, Menu $menu): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id, 404);

        $items = $menu->items()
            ->with('menuCategory')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(min(max($request->integer('items_per_page', 100), 1), 200));

        $menu->setRelation('items', $items->getCollection());
        $menu->setAttribute('items_pagination', [
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'next_page_url' => $items->nextPageUrl(),
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
            'discount_enabled' => ['sometimes', 'boolean'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (array_key_exists('discount_percent', $data)) {
            $data['discount_percent'] = MenuPricing::normalizeDiscountPercent((float) $data['discount_percent']);
        }

        $menu->update($data);

        if (array_key_exists('discount_enabled', $data) || array_key_exists('discount_percent', $data)) {
            MenuPricing::applyCommissionSnapshotForMenu($menu->fresh()->load('items.menu'));
        }

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
