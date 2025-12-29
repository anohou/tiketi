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
        Schema::create('trip_seat_occupancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trip_id')->index();
            $table->unsignedInteger('seat_number');
            $table->uuid('ticket_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['trip_id','seat_number'], 'uniq_trip_seat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_seat_occupancies');
    }
};
