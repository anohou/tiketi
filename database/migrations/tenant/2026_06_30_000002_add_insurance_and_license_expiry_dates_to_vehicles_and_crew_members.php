<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('insurance_expiry_date')->nullable()->after('seat_count');
        });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->date('license_expiry_date')->nullable()->after('license_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('insurance_expiry_date');
        });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropColumn('license_expiry_date');
        });
    }
};
