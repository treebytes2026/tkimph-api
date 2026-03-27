<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('min_spend', 10, 2)->default(0);
            $table->string('discount_type', 20); // percentage|fixed
            $table->decimal('discount_value', 10, 2);
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->unsignedInteger('global_usage_limit')->nullable();
            $table->unsignedInteger('per_user_usage_limit')->default(1);
            $table->boolean('stackable')->default(false);
            $table->json('eligible_user_ids')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('subtotal_at_apply', 10, 2);
            $table->timestamps();

            $table->index(['promotion_id', 'user_id']);
            $table->unique(['promotion_id', 'order_id']);
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->string('code', 40);
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 10, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->json('audit_meta')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('promotion_redemptions');
        Schema::dropIfExists('promotions');
    }
};
