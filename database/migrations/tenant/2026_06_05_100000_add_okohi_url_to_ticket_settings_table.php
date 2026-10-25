<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_settings', function (Blueprint $table) {
            $table->text('okohi_url')->nullable()->after('print_qr_code');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_settings', function (Blueprint $table) {
            $table->dropColumn('okohi_url');
        });
    }
};
