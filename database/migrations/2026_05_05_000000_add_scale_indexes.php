<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->index(['is_active', 'operating_status', 'cuisine_id', 'id'], 'restaurants_public_listing_idx');
            $table->index(['user_id', 'is_active', 'id'], 'restaurants_owner_active_idx');
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->index(['restaurant_id', 'is_active', 'sort_order', 'id'], 'menus_restaurant_active_sort_idx');
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->index(['menu_id', 'is_available', 'sort_order', 'id'], 'menu_items_menu_available_sort_idx');
            $table->index(['menu_category_id', 'is_available', 'id'], 'menu_items_category_available_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['restaurant_id', 'status', 'id'], 'orders_restaurant_status_id_idx');
            $table->index(['customer_id', 'status', 'id'], 'orders_customer_status_id_idx');
            $table->index(['rider_id', 'status', 'id'], 'orders_rider_status_id_idx');
            $table->index(['restaurant_id', 'status', 'placed_at'], 'orders_restaurant_status_placed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_restaurant_status_id_idx');
            $table->dropIndex('orders_customer_status_id_idx');
            $table->dropIndex('orders_rider_status_id_idx');
            $table->dropIndex('orders_restaurant_status_placed_idx');
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropIndex('menu_items_menu_available_sort_idx');
            $table->dropIndex('menu_items_category_available_idx');
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->dropIndex('menus_restaurant_active_sort_idx');
        });

        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropIndex('restaurants_public_listing_idx');
            $table->dropIndex('restaurants_owner_active_idx');
        });
    }
};
