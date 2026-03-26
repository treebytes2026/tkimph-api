<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartnerMenuItemController extends Controller
{
    public function store(Request $request, Restaurant $restaurant, Menu $menu): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id, 404);

        $data = $request->validate([
            'menu_category_id' => ['required', 'integer', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        $category = MenuCategory::query()->whereKey($data['menu_category_id'])->where('is_active', true)->first();
        abort_unless($category, 422, 'Menu category is invalid or inactive.');

        $data['sort_order'] = $data['sort_order'] ?? ((int) ($menu->items()->max('sort_order') ?? -1) + 1);
        $data['is_available'] = $data['is_available'] ?? true;

        $item = $menu->items()->create($data);

        return response()->json($item->load('menuCategory'), 201);
    }

    public function update(Request $request, Restaurant $restaurant, Menu $menu, MenuItem $item): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id && $item->menu_id === $menu->id, 404);

        $data = $request->validate([
            'menu_category_id' => ['sometimes', 'integer', 'exists:menu_categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['menu_category_id'])) {
            $category = MenuCategory::query()->whereKey($data['menu_category_id'])->where('is_active', true)->first();
            abort_unless($category, 422, 'Menu category is invalid or inactive.');
        }

        $item->update($data);

        return response()->json($item->fresh()->load('menuCategory'));
    }

    /** Upload or replace the optional photo for this dish (shown in the dish list). */
    public function uploadImage(Request $request, Restaurant $restaurant, Menu $menu, MenuItem $item): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id && $item->menu_id === $menu->id, 404);

        $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120', 'mimes:jpeg,jpg,png,webp,gif'],
        ]);

        if ($item->image_path) {
            Storage::disk('public')->delete($item->image_path);
        }

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $dir = 'menus/'.$restaurant->id.'/'.$menu->id.'/items';
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs($dir, $filename, 'public');

        $item->update(['image_path' => $path]);

        return response()->json($item->fresh()->load('menuCategory'));
    }

    public function deleteImage(Request $request, Restaurant $restaurant, Menu $menu, MenuItem $item): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id && $item->menu_id === $menu->id, 404);

        if ($item->image_path) {
            Storage::disk('public')->delete($item->image_path);
            $item->update(['image_path' => null]);
        }

        return response()->json($item->fresh()->load('menuCategory'));
    }

    public function destroy(Request $request, Restaurant $restaurant, Menu $menu, MenuItem $item): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless($menu->restaurant_id === $restaurant->id && $item->menu_id === $menu->id, 404);

        $item->delete();

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
