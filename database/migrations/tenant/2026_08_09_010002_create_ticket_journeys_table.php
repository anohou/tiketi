<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->index();
            // outbound | return
            $table->string('direction')->index();
            $table->uuid('from_station_id')->index();
            $table->uuid('to_station_id')->index();
            // fixed_trip | fixed_schedule | date_flexible | open
            $table->string('selection_mode')->default('fixed_trip');
            $table->uuid('departure_schedule_id')->nullable()->index();
            $table->date('desired_travel_date')->nullable()->index();
            $table->time('desired_departure_time')->nullable();
            $table->uuid('trip_id')->nullable()->index();
            $table->uuid('vehicle_id')->nullable()->index();
            $table->unsignedInteger('seat_number')->nullable();
            // unassigned | confirmed | reassigned
            $table->string('seat_assignment_status')->default('unassigned');
            // pending | awaiting_trip | ready | assigned | boarded | completed | cancelled | expired | missed
            $table->string('status')->default('pending')->index();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->uuid('boarded_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_journeys');
    }
};
