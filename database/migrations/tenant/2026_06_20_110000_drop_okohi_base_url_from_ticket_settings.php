<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_settings', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_settings', 'okohi_base_url')) {
                $table->dropColumn('okohi_base_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_settings', function (Blueprint $table) {
            $table->string('okohi_base_url')->nullable()->after('print_qr_code');
        });
    }
};
