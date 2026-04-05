<?php

namespace App\Support;

use App\Models\Menu;
use App\Models\MenuItem;

class MenuPricing
{
    public static function normalizeDiscountPercent(float $percent): float
    {
        return round(max(0, min(100, $percent)), 2);
    }

    public static function effectiveDiscountPercent(?Menu $menu = null, ?MenuItem $item = null): float
    {
        if ($item && (bool) $item->discount_enabled) {
            return self::normalizeDiscountPercent((float) $item->discount_percent);
        }

        if ($menu && (bool) $menu->discount_enabled) {
            return self::normalizeDiscountPercent((float) $menu->discount_percent);
        }

        return 0.0;
    }

    public static function discountedPrice(float $basePrice, float $discountPercent): float
    {
        $discount = max(0, min($basePrice, $basePrice * (self::normalizeDiscountPercent($discountPercent) / 100)));

        return round(max(0, $basePrice - $discount), 2);
    }

    public static function discountedPriceForItem(MenuItem $item): float
    {
        $menu = $item->relationLoaded('menu') ? $item->menu : $item->menu()->first();

        return self::discountedPrice((float) $item->price, self::effectiveDiscountPercent($menu, $item));
    }

    public static function applyCommissionSnapshot(MenuItem $item): void
    {
        $discountedPrice = self::discountedPriceForItem($item);

        $item->forceFill([
            'commission_rate' => PlatformPricing::commissionRate(),
            'platform_commission' => PlatformPricing::commissionAmount($discountedPrice),
            'restaurant_net' => PlatformPricing::restaurantNet($discountedPrice),
        ])->saveQuietly();
    }

    public static function applyCommissionSnapshotForMenu(Menu $menu): void
    {
        $menu->loadMissing('items.menu');

        $menu->items->each(function (MenuItem $item): void {
            self::applyCommissionSnapshot($item);
        });
    }
}
