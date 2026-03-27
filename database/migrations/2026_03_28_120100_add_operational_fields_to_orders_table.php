<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('gross_sales', 10, 2)->default(0)->after('delivery_fee');
            $table->decimal('restaurant_net', 10, 2)->default(0)->after('gross_sales');
            $table->string('cancelled_by_role', 40)->nullable()->after('assigned_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by_role');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');

            $table->index(['status', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'cancelled_at']);
            $table->dropColumn([
                'gross_sales',
                'restaurant_net',
                'cancelled_by_role',
                'cancellation_reason',
                'cancelled_at',
            ]);
        });
    }
};
