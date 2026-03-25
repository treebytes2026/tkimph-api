<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('business_type_id')->nullable()->after('user_id')->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('business_category_id')->nullable()->after('business_type_id')->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('cuisine_id')->nullable()->after('business_category_id')->constrained()->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropForeign(['business_type_id']);
            $table->dropForeign(['business_category_id']);
            $table->dropForeign(['cuisine_id']);
        });
    }
};
