<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_settlements', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('period_to');
            $table->timestamp('last_overdue_notified_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settlements', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'last_overdue_notified_at']);
        });
    }
};
