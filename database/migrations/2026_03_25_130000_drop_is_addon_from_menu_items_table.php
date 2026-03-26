<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items') || ! Schema::hasColumn('menu_items', 'is_addon')) {
            return;
        }
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('is_addon');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('is_addon')->default(false);
        });
    }
};
