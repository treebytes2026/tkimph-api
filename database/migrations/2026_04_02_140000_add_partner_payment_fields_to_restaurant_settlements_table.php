<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_settlements', function (Blueprint $table) {
            $table->string('partner_reference_number', 120)->nullable()->after('reference_number');
            $table->string('payment_proof_path', 255)->nullable()->after('partner_reference_number');
            $table->text('partner_payment_note')->nullable()->after('payment_proof_path');
            $table->timestamp('payment_submitted_at')->nullable()->after('partner_payment_note');
            $table->foreignId('payment_submitted_by_partner_id')->nullable()->constrained('users')->nullOnDelete()->after('payment_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_submitted_by_partner_id');
            $table->dropColumn([
                'partner_reference_number',
                'payment_proof_path',
                'partner_payment_note',
                'payment_submitted_at',
            ]);
        });
    }
};
