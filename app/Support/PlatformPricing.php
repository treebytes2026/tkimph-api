<?php

namespace App\Support;

use App\Models\AdminSetting;

class PlatformPricing
{
    public const DEFAULT_COMMISSION_RATE = 0.13;
    public const DEFAULT_STANDARD_DELIVERY_FEE = 49.0;

    public static function commissionRate(): float
    {
        $rate = AdminSetting::readFloat('platform_commission_rate', self::DEFAULT_COMMISSION_RATE);

        return max(0, min(1, round($rate, 4)));
    }

    public static function settlementsEnabled(): bool
    {
        return AdminSetting::readBool('settlements_enabled', false);
    }

    public static function deliveryFeeEnabled(): bool
    {
        return AdminSetting::readBool('delivery_fee_enabled', false);
    }

    public static function standardDeliveryFee(): float
    {
        $fee = AdminSetting::readFloat('standard_delivery_fee', self::DEFAULT_STANDARD_DELIVERY_FEE);

        return round(max(0, $fee), 2);
    }

    public static function activeDeliveryFee(): float
    {
        return self::deliveryFeeEnabled() ? self::standardDeliveryFee() : 0.0;
    }

    public static function commissionAmount(float $amount): float
    {
        return round(max(0, $amount) * self::commissionRate(), 2);
    }

    public static function restaurantNet(float $amount): float
    {
        return round(max(0, $amount) - self::commissionAmount($amount), 2);
    }
}
