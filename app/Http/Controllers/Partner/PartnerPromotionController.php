<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Partner\Concerns\InteractsWithPartnerRestaurants;
use App\Models\Promotion;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerPromotionController extends Controller
{
    use InteractsWithPartnerRestaurants;

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);

        $rows = Promotion::query()
            ->where('restaurant_id', $restaurant->id)
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        $data = $this->validated($request);
        $data['restaurant_id'] = $restaurant->id;
        $promotion = Promotion::query()->create($data);

        return response()->json($promotion, 201);
    }

    public function update(Request $request, Restaurant $restaurant, Promotion $promotion): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless((int) $promotion->restaurant_id === (int) $restaurant->id, 404);

        $data = $this->validated($request, true, $promotion->id);
        $promotion->update($data);

        return response()->json($promotion->fresh());
    }

    public function destroy(Request $request, Restaurant $restaurant, Promotion $promotion): JsonResponse
    {
        $this->authorizePartnerRestaurant($request, $restaurant);
        abort_unless((int) $promotion->restaurant_id === (int) $restaurant->id, 404);

        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted.']);
    }

    private function validated(Request $request, bool $partial = false, ?int $promotionId = null): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        $data = $request->validate([
            'code' => array_merge($required, ['string', 'max:40', Rule::unique('promotions', 'code')->ignore($promotionId)]),
            'name' => array_merge($required, ['string', 'max:120']),
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => array_merge($required, ['boolean']),
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'min_spend' => array_merge($required, ['numeric', 'min:0']),
            'discount_type' => array_merge($required, [Rule::in(Promotion::TYPES)]),
            'discount_value' => array_merge($required, ['numeric', 'min:0']),
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'global_usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_usage_limit' => array_merge($required, ['integer', 'min:1', 'max:100']),
            'stackable' => array_merge($required, ['boolean']),
            'auto_apply' => array_merge($required, ['boolean']),
            'first_order_only' => array_merge($required, ['boolean']),
            'priority' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'eligible_user_ids' => ['nullable', 'array'],
            'eligible_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return $data;
    }
}

