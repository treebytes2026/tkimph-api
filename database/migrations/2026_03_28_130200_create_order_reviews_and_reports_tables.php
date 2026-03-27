<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('restaurant_rating');
            $table->unsignedTinyInteger('rider_rating')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 30)->default('published'); // published|hidden|under_review
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['restaurant_id', 'status']);
            $table->index(['rider_id', 'status']);
        });

        Schema::create('order_review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_review_id')->constrained('order_reviews')->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 120);
            $table->text('details')->nullable();
            $table->string('status', 30)->default('open'); // open|resolved|rejected
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['order_review_id', 'status']);
            $table->unique(['order_review_id', 'reported_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_review_reports');
        Schema::dropIfExists('order_reviews');
    }
};
