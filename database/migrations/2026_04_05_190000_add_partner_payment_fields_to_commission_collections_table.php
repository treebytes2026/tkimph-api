<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_collections', function (Blueprint $table) {
            $table->string('partner_payment_method', 20)->nullable()->after('collection_reference');
            $table->string('partner_reference_number', 120)->nullable()->after('partner_payment_method');
            $table->string('payment_proof_path', 255)->nullable()->after('partner_reference_number');
            $table->text('partner_payment_note')->nullable()->after('payment_proof_path');
            $table->timestamp('payment_submitted_at')->nullable()->after('partner_payment_note');
            $table->foreignId('payment_submitted_by_partner_id')->nullable()->after('payment_submitted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_submitted_by_partner_id');
            $table->dropColumn([
                'partner_payment_method',
                'partner_reference_number',
                'payment_proof_path',
                'partner_payment_note',
                'payment_submitted_at',
            ]);
        });
    }
};
