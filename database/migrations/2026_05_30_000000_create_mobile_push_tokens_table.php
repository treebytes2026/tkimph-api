<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 32)->nullable();
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('role', 40)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->index(['role', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_tokens');
    }
};
