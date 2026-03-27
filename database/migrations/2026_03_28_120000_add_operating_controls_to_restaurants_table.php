<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('operating_status', 40)->default('open')->after('is_active');
            $table->text('operating_note')->nullable()->after('operating_status');
            $table->timestamp('paused_until')->nullable()->after('operating_note');

            $table->index(['is_active', 'operating_status']);
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'operating_status']);
            $table->dropColumn(['operating_status', 'operating_note', 'paused_until']);
        });
    }
};
