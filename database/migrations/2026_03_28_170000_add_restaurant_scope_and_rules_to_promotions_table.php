<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->nullable()->after('id')->constrained('restaurants')->nullOnDelete();
            $table->boolean('auto_apply')->default(false)->after('stackable');
            $table->boolean('first_order_only')->default(false)->after('auto_apply');
            $table->unsignedTinyInteger('priority')->default(0)->after('first_order_only');

            $table->index(['restaurant_id', 'is_active', 'starts_at', 'ends_at'], 'promotions_restaurant_active_window_idx');
            $table->index(['restaurant_id', 'auto_apply'], 'promotions_restaurant_auto_idx');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex('promotions_restaurant_active_window_idx');
            $table->dropIndex('promotions_restaurant_auto_idx');
            $table->dropConstrainedForeignId('restaurant_id');
            $table->dropColumn(['auto_apply', 'first_order_only', 'priority']);
        });
    }
};

