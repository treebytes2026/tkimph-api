<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Collection;

class PromotionEngine
{
    /**
     * Validate one code for preview on customer side.
     */
    public static function evaluateCode(
        ?string $promoCode,
        float $subtotal,
        User $customer,
        ?Restaurant $restaurant = null
    ): array {
        $result = self::evaluatePromotions($promoCode ? [$promoCode] : [], $subtotal, $customer, $restaurant, false);
        $best = $result['applied_promotions'][0] ?? null;

        return [
            'valid' => $best !== null,
            'code' => $best['code'] ?? strtoupper(trim((string) $promoCode)) ?: null,
            'discount_amount' => (float) ($best['discount_amount'] ?? 0),
            'promotion' => $best['promotion'] ?? null,
            'audit_meta' => $best['audit_meta'] ?? ['reason' => 'not_eligible'],
            'invalid_reasons' => $result['invalid_reasons'],
        ];
    }

    /**
     * Evaluate promo codes + optional auto promotions for an order.
     *
     * @param  array<int, string>  $promoCodes
     * @return array{
     *   valid: bool,
     *   discount_amount: float,
     *   applied_promotions: array<int, array{
     *      promotion: Promotion,
     *      code: string,
     *      discount_amount: float,
     *      audit_meta: array<string, mixed>
     *   }>,
     *   invalid_reasons: array<string, array<string, mixed>>
     * }
     */
    public static function evaluatePromotions(
        array $promoCodes,
        float $subtotal,
        User $customer,
        ?Restaurant $restaurant = null,
        bool $includeAutoPromotions = true
    ): array {
        $normalizedCodes = collect($promoCodes)
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        $invalidReasons = [];
        $validSelections = [];

        foreach ($normalizedCodes as $code) {
            $promotion = Promotion::query()->where('code', $code)->first();
            if (! $promotion) {
                $invalidReasons[$code] = ['reason' => 'code_not_found'];
                continue;
            }

            $validation = self::validatePromotion($promotion, $subtotal, $customer, $restaurant);
            if (! $validation['valid']) {
                $invalidReasons[$code] = $validation['audit_meta'];
                continue;
            }

            $validSelections[] = $validation;
        }

        if ($includeAutoPromotions) {
            $autoPromotions = self::autoPromotionsForRestaurant($restaurant);
            foreach ($autoPromotions as $promotion) {
                if (in_array($promotion->code, $normalizedCodes->all(), true)) {
                    continue;
                }
                $validation = self::validatePromotion($promotion, $subtotal, $customer, $restaurant);
                if ($validation['valid']) {
                    $validSelections[] = $validation;
                }
            }
        }

        if ($normalizedCodes->count() > 1 && collect($validSelections)->contains(
            fn (array $row) => ($row['promotion']->stackable ?? false) === false
        )) {
            return [
                'valid' => false,
                'discount_amount' => 0.0,
                'applied_promotions' => [],
                'invalid_reasons' => array_merge($invalidReasons, [
                    'stacking' => ['reason' => 'stacking_not_allowed'],
                ]),
            ];
        }

        $selected = self::resolveStacking($validSelections);
        $discountTotal = round(collect($selected)->sum('discount_amount'), 2);

        return [
            'valid' => $selected !== [],
            'discount_amount' => $discountTotal,
            'applied_promotions' => $selected,
            'invalid_reasons' => $invalidReasons,
        ];
    }

    /**
     * @return Collection<int, Promotion>
     */
    private static function autoPromotionsForRestaurant(?Restaurant $restaurant): Collection
    {
        return Promotion::query()
            ->activeAt(now())
            ->where('auto_apply', true)
            ->when($restaurant, function ($q) use ($restaurant) {
                $q->where(function ($inner) use ($restaurant) {
                    $inner->whereNull('restaurant_id')
                        ->orWhere('restaurant_id', $restaurant->id);
                });
            }, fn ($q) => $q->whereNull('restaurant_id'))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array{
     *   valid: bool,
     *   code: string,
     *   discount_amount: float,
     *   promotion: Promotion|null,
     *   audit_meta: array<string, mixed>
     * }
     */
    private static function validatePromotion(
        Promotion $promotion,
        float $subtotal,
        User $customer,
        ?Restaurant $restaurant = null
    ): array {
        $code = strtoupper((string) $promotion->code);

        if ($restaurant !== null && $promotion->restaurant_id !== null && (int) $promotion->restaurant_id !== (int) $restaurant->id) {
            return self::invalid($code, 'restaurant_mismatch');
        }

        if ($promotion->restaurant_id !== null && $restaurant === null) {
            return self::invalid($code, 'restaurant_required');
        }

        if (! $promotion->is_active) {
            return self::invalid($code, 'inactive');
        }

        $now = now();
        if ($promotion->starts_at && $promotion->starts_at->gt($now)) {
            return self::invalid($code, 'not_started');
        }
        if ($promotion->ends_at && $promotion->ends_at->lt($now)) {
            return self::invalid($code, 'expired');
        }
        if ($subtotal < (float) $promotion->min_spend) {
            return self::invalid($code, 'min_spend_not_met', ['min_spend' => (float) $promotion->min_spend]);
        }

        $eligibleUsers = collect($promotion->eligible_user_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();
        if ($eligibleUsers->isNotEmpty() && ! $eligibleUsers->contains((int) $customer->id)) {
            return self::invalid($code, 'user_not_eligible');
        }

        if ((bool) $promotion->first_order_only) {
            $priorOrderExists = Order::query()
                ->where('customer_id', $customer->id)
                ->when(
                    $promotion->restaurant_id !== null,
                    fn ($q) => $q->where('restaurant_id', $promotion->restaurant_id)
                )
                ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED, Order::STATUS_UNDELIVERABLE])
                ->exists();
            if ($priorOrderExists) {
                return self::invalid($code, 'first_order_only');
            }
        }

        if ($promotion->global_usage_limit !== null) {
            $globalCount = $promotion->redemptions()->count();
            if ($globalCount >= (int) $promotion->global_usage_limit) {
                return self::invalid($code, 'global_cap_reached');
            }
        }

        $customerCount = $promotion->redemptions()->where('user_id', $customer->id)->count();
        if ($customerCount >= (int) $promotion->per_user_usage_limit) {
            return self::invalid($code, 'per_user_cap_reached');
        }

        $discountAmount = self::computeDiscountAmount($promotion, $subtotal);
        if ($discountAmount <= 0) {
            return self::invalid($code, 'zero_discount');
        }

        return [
            'valid' => true,
            'code' => $code,
            'discount_amount' => $discountAmount,
            'promotion' => $promotion,
            'audit_meta' => [
                'promotion_id' => $promotion->id,
                'promotion_name' => $promotion->name,
                'restaurant_id' => $promotion->restaurant_id,
                'discount_type' => $promotion->discount_type,
                'discount_value' => (float) $promotion->discount_value,
                'max_discount_amount' => $promotion->max_discount_amount !== null ? (float) $promotion->max_discount_amount : null,
                'stackable' => (bool) $promotion->stackable,
                'auto_apply' => (bool) $promotion->auto_apply,
                'first_order_only' => (bool) $promotion->first_order_only,
                'priority' => (int) $promotion->priority,
                'min_spend' => (float) $promotion->min_spend,
                'subtotal' => round($subtotal, 2),
            ],
        ];
    }

    /**
     * @param  array<int, array{
     *   promotion: Promotion,
     *   code: string,
     *   discount_amount: float,
     *   audit_meta: array<string, mixed>
     * }>  $validSelections
     * @return array<int, array{
     *   promotion: Promotion,
     *   code: string,
     *   discount_amount: float,
     *   audit_meta: array<string, mixed>
     * }>
     */
    private static function resolveStacking(array $validSelections): array
    {
        if ($validSelections === []) {
            return [];
        }

        usort($validSelections, static function (array $a, array $b): int {
            $aPriority = (int) ($a['promotion']->priority ?? 0);
            $bPriority = (int) ($b['promotion']->priority ?? 0);
            if ($aPriority !== $bPriority) {
                return $bPriority <=> $aPriority;
            }

            if ((float) $a['discount_amount'] !== (float) $b['discount_amount']) {
                return (float) $b['discount_amount'] <=> (float) $a['discount_amount'];
            }

            return (int) $b['promotion']->id <=> (int) $a['promotion']->id;
        });

        $stackableSelections = array_filter($validSelections, fn (array $row) => (bool) $row['promotion']->stackable);
        $nonStackableSelections = array_filter($validSelections, fn (array $row) => ! (bool) $row['promotion']->stackable);

        if ($nonStackableSelections !== []) {
            $bestNonStackable = array_values($nonStackableSelections)[0];
            $stackableTotal = round((float) array_sum(array_map(
                static fn (array $row) => (float) $row['discount_amount'],
                $stackableSelections
            )), 2);

            if ($stackableTotal > (float) $bestNonStackable['discount_amount']) {
                return array_values($stackableSelections);
            }

            return [$bestNonStackable];
        }

        return array_values($stackableSelections);
    }

    private static function computeDiscountAmount(Promotion $promotion, float $subtotal): float
    {
        $raw = $promotion->discount_type === Promotion::TYPE_PERCENTAGE
            ? ($subtotal * ((float) $promotion->discount_value / 100))
            : (float) $promotion->discount_value;

        if ($promotion->max_discount_amount !== null) {
            $raw = min($raw, (float) $promotion->max_discount_amount);
        }

        $raw = min($raw, $subtotal);

        return round(max(0, $raw), 2);
    }

    /**
     * @return array{
     *   valid: false,
     *   code: string,
     *   discount_amount: float,
     *   promotion: null,
     *   audit_meta: array<string, mixed>
     * }
     */
    private static function invalid(string $code, string $reason, array $extra = []): array
    {
        return [
            'valid' => false,
            'code' => $code,
            'discount_amount' => 0.0,
            'promotion' => null,
            'audit_meta' => array_merge(['reason' => $reason], $extra),
        ];
    }
}

