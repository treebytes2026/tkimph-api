<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'profile_image_path')) {
                $table->string('profile_image_path', 512)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurants', 'profile_image_path')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('profile_image_path');
            });
        }
    }
};
