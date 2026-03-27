<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Promotion::query()
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $promotion = Promotion::query()->create($data);

        return response()->json($promotion, 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $data = $this->validated($request, true);
        $promotion->update($data);

        return response()->json($promotion->fresh());
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        $data = $request->validate([
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'code' => array_merge($required, ['string', 'max:40', Rule::unique('promotions', 'code')->ignore($request->route('promotion'))]),
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
