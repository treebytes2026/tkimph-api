<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->string('owner_first_name')->nullable()->after('business_name');
            $table->string('owner_last_name')->nullable()->after('owner_first_name');
        });

        foreach (DB::table('partner_applications')->orderBy('id')->cursor() as $row) {
            $raw = trim((string) ($row->contact_name ?? ''));
            $parts = $raw === '' ? [] : preg_split('/\s+/u', $raw, 2);
            $first = $parts[0] ?? '';
            $last = $parts[1] ?? '';
            if ($first === '') {
                $first = 'Unknown';
            }

            DB::table('partner_applications')->where('id', $row->id)->update([
                'owner_first_name' => $first,
                'owner_last_name' => $last,
            ]);
        }

        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('id');
        });

        foreach (DB::table('partner_applications')->orderBy('id')->cursor() as $row) {
            $combined = trim(($row->owner_first_name ?? '').' '.($row->owner_last_name ?? ''));
            DB::table('partner_applications')->where('id', $row->id)->update([
                'contact_name' => $combined !== '' ? $combined : 'Unknown',
            ]);
        }

        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn(['owner_first_name', 'owner_last_name']);
        });
    }
};
