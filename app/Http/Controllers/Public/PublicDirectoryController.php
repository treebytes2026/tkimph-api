<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Cuisine;
use App\Models\MenuItem;
use App\Models\MenuItemReview;
use App\Models\OrderReview;
use App\Models\Promotion;
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
            ->where('operating_status', Restaurant::OPERATING_STATUS_OPEN)
            ->with([
                'cuisine:id,name',
                'businessType:id,name',
                'menus' => static function ($q) {
                    $q->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->select('id', 'restaurant_id', 'name', 'sort_order');
                },
                'promotions' => static function ($q) {
                    $q->activeAt(now())
                        ->orderByDesc('priority')
                        ->orderByDesc('id');
                },
            ])
            ->withAvg([
                'reviews as reviews_avg_restaurant_rating' => static fn ($q) => $q->where('status', OrderReview::STATUS_PUBLISHED),
            ], 'restaurant_rating')
            ->withCount([
                'reviews as reviews_count' => static fn ($q) => $q->where('status', OrderReview::STATUS_PUBLISHED),
            ]);

        if ($request->filled('cuisine_id')) {
            $query->where('cuisine_id', $request->integer('cuisine_id'));
        }

        $limit = min(max($request->integer('limit', 24), 1), 60);
        $rows = $query
            ->orderByDesc('id')
            ->get();
        $rows = $rows->filter(fn (Restaurant $restaurant) => $restaurant->isOperationallyAvailable())
            ->take($limit)
            ->values();
        $total = $rows->count();

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
     * Landing â€œAll restaurantsâ€: each entry is a restaurant plus full menus + items (same shape as GET /public/restaurants/{slug}).
     */
    public function restaurantsMenuFeed(Request $request): JsonResponse
    {
        $query = Restaurant::query()
            ->where('is_active', true)
            ->where('operating_status', Restaurant::OPERATING_STATUS_OPEN)
            ->with([
                'cuisine:id,name',
                'businessType:id,name',
                'promotions' => static function ($q) {
                    $q->activeAt(now())
                        ->orderByDesc('priority')
                        ->orderByDesc('id');
                },
            ])
            ->withAvg([
                'reviews as reviews_avg_restaurant_rating' => static fn ($q) => $q->where('status', OrderReview::STATUS_PUBLISHED),
            ], 'restaurant_rating')
            ->withCount([
                'reviews as reviews_count' => static fn ($q) => $q->where('status', OrderReview::STATUS_PUBLISHED),
            ]);

        if ($request->filled('cuisine_id')) {
            $query->where('cuisine_id', $request->integer('cuisine_id'));
        }

        $limit = min(max($request->integer('limit', 24), 1), 60);
        $rows = $query
            ->orderByDesc('id')
            ->get();
        $rows = $rows->filter(fn (Restaurant $restaurant) => $restaurant->isOperationallyAvailable())
            ->take($limit)
            ->values();
        $total = $rows->count();

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
                ->withAvg([
                    'reviews as reviews_avg_rating' => static fn ($q) => $q->where('status', MenuItemReview::STATUS_PUBLISHED),
                ], 'rating')
                ->withCount([
                    'reviews as reviews_count' => static fn ($q) => $q->where('status', MenuItemReview::STATUS_PUBLISHED),
                ])
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
            ->where('operating_status', Restaurant::OPERATING_STATUS_OPEN)
            ->with([
                'cuisine:id,name',
                'businessType:id,name',
                'locationImages' => static function ($q) {
                    $q->orderBy('sort_order')->orderBy('id');
                },
                'promotions' => static function ($q) {
                    $q->activeAt(now())
                        ->orderByDesc('priority')
                        ->orderByDesc('id');
                },
            ])
            ->withAvg([
                'reviews as reviews_avg_restaurant_rating' => static fn ($q) => $q->where('status', OrderReview::STATUS_PUBLISHED),
            ], 'restaurant_rating')
            ->withCount([
                'reviews as reviews_count' => static fn ($q) => $q->where('status', OrderReview::STATUS_PUBLISHED),
            ])
            ->firstOrFail();

        abort_unless($restaurant->isOperationallyAvailable(), 404);

        $items = MenuItem::query()
            ->whereHas('menu', function ($q) use ($restaurant) {
                $q->where('restaurant_id', $restaurant->id)->where('is_active', true);
            })
            ->where('is_available', true)
            ->with(['menu:id,name,sort_order'])
            ->withAvg([
                'reviews as reviews_avg_rating' => static fn ($q) => $q->where('status', MenuItemReview::STATUS_PUBLISHED),
            ], 'rating')
            ->withCount([
                'reviews as reviews_count' => static fn ($q) => $q->where('status', MenuItemReview::STATUS_PUBLISHED),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $reviews = OrderReview::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', OrderReview::STATUS_PUBLISHED)
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'restaurant' => $this->serializeRestaurant($restaurant, true),
            'menus' => $this->buildMenusPayloadFromItems($items),
            'reviews' => $reviews->map(fn (OrderReview $review) => [
                'id' => $review->id,
                'restaurant_rating' => $review->restaurant_rating,
                'comment' => $review->comment,
                'customer_name' => $review->customer?->name,
                'created_at' => $review->created_at?->toIso8601String(),
            ])->values()->all(),
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
            'rating' => round((float) ($item->reviews_avg_rating ?? 0), 1),
            'review_count' => (int) ($item->reviews_count ?? 0),
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
            'publicly_orderable' => $r->isOperationallyAvailable(),
            'menus' => $r->relationLoaded('menus')
                ? $r->menus->map(static fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                ])->values()->all()
                : [],
        ]);
    }

    /**
     * @return array{
     *   rating: float,
     *   review_count: int,
     *   delivery_min_minutes: int,
     *   delivery_max_minutes: int,
     *   delivery_fee_php: int,
     *   free_delivery_min_spend_php: int,
     *   price_level: int,
     *   promo_label: string|null,
     *   promotions: array<int, array<string, mixed>>,
     *   is_ad: bool,
     * }
     */
    private function listingMeta(Restaurant $r): array
    {
        $rating = (float) ($r->reviews_avg_restaurant_rating ?? 0);
        $reviewCount = (int) ($r->reviews_count ?? 0);
        $h = crc32((string) $r->id);
        $dMin = 15 + abs($h % 20);
        $dMax = $dMin + 10 + abs($h % 25);
        $fees = [39, 49, 59, 69];
        $fee = $fees[$h % count($fees)];
        $freeMin = [199, 299, 399][(int) (abs($h >> 3) % 3)];
        $level = 1 + abs($h % 3);
        $promotions = $this->serializePromotions($r);

        return [
            'rating' => round($rating, 1),
            'review_count' => $reviewCount,
            'delivery_min_minutes' => $dMin,
            'delivery_max_minutes' => $dMax,
            'delivery_fee_php' => $fee,
            'free_delivery_min_spend_php' => $freeMin,
            'price_level' => $level,
            'promo_label' => $this->bestPromoLabel($promotions),
            'promotions' => $promotions,
            'is_ad' => ($h % 7 === 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializePromotions(Restaurant $restaurant): array
    {
        if (! $restaurant->relationLoaded('promotions')) {
            return [];
        }

        return $restaurant->promotions
            ->map(function (Promotion $promotion): array {
                $discountText = $promotion->discount_type === Promotion::TYPE_PERCENTAGE
                    ? rtrim(rtrim(number_format((float) $promotion->discount_value, 2, '.', ''), '0'), '.').'% off'
                    : 'PHP '.number_format((float) $promotion->discount_value, 2).' off';
                $minSpendText = (float) $promotion->min_spend > 0
                    ? 'min PHP '.number_format((float) $promotion->min_spend, 0)
                    : null;

                return [
                    'id' => $promotion->id,
                    'code' => $promotion->code,
                    'name' => $promotion->name,
                    'min_spend' => (float) $promotion->min_spend,
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => (float) $promotion->discount_value,
                    'max_discount_amount' => $promotion->max_discount_amount !== null ? (float) $promotion->max_discount_amount : null,
                    'stackable' => (bool) $promotion->stackable,
                    'auto_apply' => (bool) $promotion->auto_apply,
                    'first_order_only' => (bool) $promotion->first_order_only,
                    'ends_at' => $promotion->ends_at?->toIso8601String(),
                    'display_label' => trim($discountText.($minSpendText ? ' | '.$minSpendText : '')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $promotions
     */
    private function bestPromoLabel(array $promotions): ?string
    {
        if ($promotions === []) {
            return null;
        }

        $label = (string) ($promotions[0]['display_label'] ?? '');

        return $label !== '' ? $label : null;
    }
}
