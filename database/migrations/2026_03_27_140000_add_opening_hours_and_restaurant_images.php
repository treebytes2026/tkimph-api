<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'opening_hours')) {
                $table->json('opening_hours')->nullable()->after('address');
            }
        });

        if (! Schema::hasTable('restaurant_images')) {
            Schema::create('restaurant_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
                $table->string('path', 512);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_images');
        if (Schema::hasColumn('restaurants', 'opening_hours')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('opening_hours');
            });
        }
    }
};
