<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Unauthenticated listing for the marketing site: cuisines and active restaurants.
 */
class PublicDirectoryController extends Controller
{
    public function cuisines(): JsonResponse
    {
        $rows = Cuisine::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order']);

        return response()->json(['data' => $rows]);
    }

    public function restaurants(Request $request): JsonResponse
    {
        $query = Restaurant::query()
            ->where('is_active', true)
            ->with([
                'cuisine:id,name',
                'businessType:id,name',
                'menus' => static function ($q) {
                    $q->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->select('id', 'restaurant_id', 'name', 'sort_order');
                },
            ]);

        if ($request->filled('cuisine_id')) {
            $query->where('cuisine_id', $request->integer('cuisine_id'));
        }

        $total = (clone $query)->count();
        $limit = min(max($request->integer('limit', 24), 1), 60);
        $rows = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $restaurant) {
            if (blank($restaurant->slug)) {
                $restaurant->slug = Str::slug($restaurant->name).'-'.Str::random(4);
                $restaurant->saveQuietly();
            }
        }

        return response()->json([
            'data' => $rows->map(fn (Restaurant $r) => $this->serializeRestaurant($r, true)),
            'meta' => [
                'total' => $total,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Landing “All restaurants”: each entry is a restaurant plus full menus + items (same shape as GET /public/restaurants/{slug}).
     */
    public function restaurantsMenuFeed(Request $request): JsonResponse
    {
        $query = Restaurant::query()
            ->where('is_active', true)
            ->with(['cuisine:id,name', 'businessType:id,name']);

        if ($request->filled('cuisine_id')) {
            $query->where('cuisine_id', $request->integer('cuisine_id'));
        }

        $total = (clone $query)->count();
        $limit = min(max($request->integer('limit', 24), 1), 60);
        $rows = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $restaurant) {
            if (blank($restaurant->slug)) {
                $restaurant->slug = Str::slug($restaurant->name).'-'.Str::random(4);
                $restaurant->saveQuietly();
            }
        }

        $restaurantIds = $rows->pluck('id');
        $itemsByRestaurant = collect();
        if ($restaurantIds->isNotEmpty()) {
            $allItems = MenuItem::query()
                ->whereHas('menu', static function ($q) use ($restaurantIds) {
                    $q->whereIn('restaurant_id', $restaurantIds)->where('is_active', true);
                })
                ->where('is_available', true)
                ->with(['menu:id,name,sort_order,restaurant_id'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            $itemsByRestaurant = $allItems->groupBy(fn (MenuItem $i) => $i->menu->restaurant_id);
        }

        $data = $rows->map(function (Restaurant $r) use ($itemsByRestaurant) {
            $items = $itemsByRestaurant->get($r->id, collect());

            return [
                // Include listing meta (rating, delivery, promo, is_ad) so dish cards match Foodpanda-style UI.
                'restaurant' => $this->serializeRestaurant($r, true),
                'menus' => $this->buildMenusPayloadFromItems($items),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $restaurant = Restaurant::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'cuisine:id,name',
                'businessType:id,name',
                'locationImages' => static function ($q) {
                    $q->orderBy('sort_order')->orderBy('id');
                },
            ])
            ->firstOrFail();

        $items = MenuItem::query()
            ->whereHas('menu', function ($q) use ($restaurant) {
                $q->where('restaurant_id', $restaurant->id)->where('is_active', true);
            })
            ->where('is_available', true)
            ->with(['menu:id,name,sort_order'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurant' => $this->serializeRestaurant($restaurant, true),
            'menus' => $this->buildMenusPayloadFromItems($items),
        ]);
    }

    /**
     * @return array<int, array{menu: array{id: int, name: string, sort_order: int}, items: \Illuminate\Support\Collection}>
     */
    private function buildMenusPayloadFromItems(Collection $items): array
    {
        return $items
            ->groupBy('menu_id')
            ->map(function ($group) {
                $first = $group->first();
                $menu = $first->menu;

                return [
                    'menu' => [
                        'id' => $menu->id,
                        'name' => $menu->name,
                        'sort_order' => $menu->sort_order,
                    ],
                    'items' => $group->map(fn (MenuItem $i) => $this->serializeMenuItem($i))->values(),
                ];
            })
            ->values()
            ->sortBy(fn (array $s) => $s['menu']['sort_order'])
            ->values()
            ->all();
    }

    private function serializeMenuItem(MenuItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'price' => $item->price,
            'image_path' => $item->image_path,
            'image_url' => $item->image_path
                ? Storage::disk('public')->url($item->image_path)
                : null,
        ];
    }

    /**
     * @param  bool  $includeListingMeta  Extra fields for the public listing only (landing page cards).
     */
    private function serializeRestaurant(Restaurant $r, bool $includeListingMeta = false): array
    {
        $base = [
            'id' => $r->id,
            'name' => $r->name,
            'slug' => $r->slug,
            'description' => $r->description,
            'phone' => $r->phone,
            'address' => $r->address,
            'opening_hours' => $r->opening_hours,
            'location_images' => $r->relationLoaded('locationImages')
                ? $r->locationImages->map(static fn (RestaurantImage $img) => [
                    'id' => $img->id,
                    'path' => $img->path,
                    'url' => $img->path ? Storage::disk('public')->url($img->path) : null,
                    'sort_order' => $img->sort_order,
                ])->values()->all()
                : [],
            'profile_image_path' => $r->profile_image_path,
            'profile_image_url' => $r->profile_image_path
                ? Storage::disk('public')->url($r->profile_image_path)
                : null,
            'cuisine' => $r->cuisine ? [
                'id' => $r->cuisine->id,
                'name' => $r->cuisine->name,
            ] : null,
            'business_type' => $r->businessType ? [
                'id' => $r->businessType->id,
                'name' => $r->businessType->name,
            ] : null,
        ];

        if (! $includeListingMeta) {
            return $base;
        }

        return array_merge($base, $this->listingMeta($r), [
            'menus' => $r->relationLoaded('menus')
                ? $r->menus->map(static fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                ])->values()->all()
                : [],
        ]);
    }

    /**
     * Stable listing-only fields for the marketing site (until real metrics exist in DB).
     *
     * @return array{
     *   rating: float,
     *   review_count: int,
     *   delivery_min_minutes: int,
     *   delivery_max_minutes: int,
     *   delivery_fee_php: int,
     *   free_delivery_min_spend_php: int,
     *   price_level: int,
     *   promo_label: string|null,
     *   is_ad: bool,
     * }
     */
    private function listingMeta(Restaurant $r): array
    {
        $h = crc32((string) $r->id);
        $rating = 4.0 + ($h % 11) / 10;
        if ($rating > 5.0) {
            $rating = 5.0;
        }
        $reviewCount = 50 + abs($h % 5000) * 11 + abs($h % 97);
        $dMin = 15 + abs($h % 20);
        $dMax = $dMin + 10 + abs($h % 25);
        $fees = [39, 49, 59, 69];
        $fee = $fees[$h % count($fees)];
        $freeMin = [199, 299, 399][(int) (abs($h >> 3) % 3)];
        $level = 1 + abs($h % 3);

        return [
            'rating' => round($rating, 1),
            'review_count' => $reviewCount,
            'delivery_min_minutes' => $dMin,
            'delivery_max_minutes' => $dMax,
            'delivery_fee_php' => $fee,
            'free_delivery_min_spend_php' => $freeMin,
            'price_level' => $level,
            'promo_label' => ($h % 3 === 0) ? '10% off ₱450' : null,
            'is_ad' => ($h % 7 === 0),
        ];
    }
}
