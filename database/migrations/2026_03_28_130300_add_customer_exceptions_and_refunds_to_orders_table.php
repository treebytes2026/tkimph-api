<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discounts_total', 10, 2)->default(0)->after('delivery_fee');
            $table->string('refund_status', 30)->default('not_required')->after('payment_status');
            $table->timestamp('refund_requested_at')->nullable()->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('refund_requested_at');
            $table->string('refund_reference', 120)->nullable()->after('refunded_at');
            $table->text('refund_reason')->nullable()->after('refund_reference');
            $table->timestamp('customer_cancel_requested_at')->nullable()->after('cancelled_at');
            $table->text('customer_cancel_reason')->nullable()->after('customer_cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'discounts_total',
                'refund_status',
                'refund_requested_at',
                'refunded_at',
                'refund_reference',
                'refund_reason',
                'customer_cancel_requested_at',
                'customer_cancel_reason',
            ]);
        });
    }
};
