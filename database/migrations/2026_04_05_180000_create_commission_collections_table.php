<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('restaurant_net', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('collection_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'period_from', 'period_to'], 'commission_collection_period_unique');
            $table->index(['status', 'period_from', 'period_to'], 'commission_collection_status_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_collections');
    }
};
