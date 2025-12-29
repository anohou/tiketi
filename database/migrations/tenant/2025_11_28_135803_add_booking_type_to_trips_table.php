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
        Schema::table('trips', function (Blueprint $table) {
            $table->enum('booking_type', ['seat_assignment', 'bulk'])
                  ->default('seat_assignment')
                  ->after('status')
                  ->index()
                  ->comment('Type de réservation: seat_assignment (placement intelligent) ou bulk (en vrac)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('booking_type');
        });
    }
};
