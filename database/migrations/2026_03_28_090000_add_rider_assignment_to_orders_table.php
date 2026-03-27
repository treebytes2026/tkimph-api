<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('rider_id')->nullable()->after('restaurant_id')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('placed_at');
            $table->index(['rider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn('assigned_at');
        });
    }
};

