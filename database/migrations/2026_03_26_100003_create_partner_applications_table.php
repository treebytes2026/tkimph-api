<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_applications', function (Blueprint $table) {
            $table->id();
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone', 50);
            $table->string('business_name');
            $table->foreignId('business_type_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('business_category_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('cuisine_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_applications');
    }
};
