<?php

use App\Models\MenuItem;
use App\Support\PlatformPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 4)->default(PlatformPricing::commissionRate())->after('price');
            $table->decimal('platform_commission', 10, 2)->default(0)->after('commission_rate');
            $table->decimal('restaurant_net', 10, 2)->default(0)->after('platform_commission');
        });

        MenuItem::query()->each(function (MenuItem $item): void {
            $price = (float) $item->price;
            $item->forceFill([
                'commission_rate' => PlatformPricing::commissionRate(),
                'platform_commission' => PlatformPricing::commissionAmount($price),
                'restaurant_net' => PlatformPricing::restaurantNet($price),
            ])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn([
                'commission_rate',
                'platform_commission',
                'restaurant_net',
            ]);
        });
    }
};
