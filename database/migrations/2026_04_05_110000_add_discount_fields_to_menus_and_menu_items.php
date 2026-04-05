<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table): void {
            $table->boolean('discount_enabled')->default(false)->after('is_active');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('discount_enabled');
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->boolean('discount_enabled')->default(false)->after('restaurant_net');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('discount_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropColumn(['discount_enabled', 'discount_percent']);
        });

        Schema::table('menus', function (Blueprint $table): void {
            $table->dropColumn(['discount_enabled', 'discount_percent']);
        });
    }
};
