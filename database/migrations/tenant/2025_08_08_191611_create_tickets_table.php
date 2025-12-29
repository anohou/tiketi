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
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticket_number')->unique();
            $table->uuid('trip_id')->index();
            $table->uuid('vehicle_id')->index();
            $table->unsignedInteger('seat_number')->index();
            $table->uuid('from_stop_id')->index();
            $table->uuid('to_stop_id')->index();
            $table->string('passenger_name');
            $table->string('passenger_phone');
            $table->unsignedInteger('price');
            $table->uuid('seller_id')->index();
            $table->uuid('station_id')->nullable()->index();
            $table->enum('status', ['issued','cancelled','refunded'])->default('issued')->index();
            $table->json('qr_payload')->nullable();
            $table->string('qr_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
