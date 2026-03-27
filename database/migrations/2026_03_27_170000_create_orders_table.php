<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('status', 40)->default('pending');
            $table->string('payment_method', 40)->default('cod');
            $table->string('payment_status', 40)->default('unpaid');
            $table->string('delivery_mode', 20)->default('delivery');
            $table->text('delivery_address');
            $table->string('delivery_floor', 120)->nullable();
            $table->text('delivery_note')->nullable();
            $table->string('location_label', 40)->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('service_fee', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->timestamp('placed_at')->useCurrent();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
