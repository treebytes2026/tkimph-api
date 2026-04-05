<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_applications', function (Blueprint $table) {
            $table->string('id_document_path', 500)->nullable()->after('license_number');
            $table->string('license_document_path', 500)->nullable()->after('id_document_path');
        });
    }

    public function down(): void
    {
        Schema::table('rider_applications', function (Blueprint $table) {
            $table->dropColumn(['id_document_path', 'license_document_path']);
        });
    }
};
