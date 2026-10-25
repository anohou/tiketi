<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_journey_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_journey_id')->index();
            $table->uuid('previous_trip_id')->nullable();
            $table->uuid('new_trip_id')->nullable();
            $table->unsignedInteger('previous_seat_number')->nullable();
            $table->unsignedInteger('new_seat_number')->nullable();
            $table->string('reason')->nullable();
            // automatic | manual
            $table->string('mode')->default('manual');
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_journey_assignments');
    }
};
