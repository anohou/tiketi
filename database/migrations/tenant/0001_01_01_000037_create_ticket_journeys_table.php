<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_journeys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->string('direction');
            $table->uuid('from_station_id');
            $table->uuid('to_station_id');
            $table->string('selection_mode')->default('fixed_trip');
            $table->uuid('departure_schedule_id')->nullable();
            $table->date('desired_travel_date')->nullable();
            $table->time('desired_departure_time')->nullable();
            $table->uuid('trip_id')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->integer('seat_number')->nullable();
            $table->string('seat_assignment_status')->default('unassigned');
            $table->string('status')->default('pending');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->uuid('boarded_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('departure_schedule_id', 'ticket_journeys_departure_schedule_id_index');
            $table->index('desired_travel_date', 'ticket_journeys_desired_travel_date_index');
            $table->index('direction', 'ticket_journeys_direction_index');
            $table->index('from_station_id', 'ticket_journeys_from_station_id_index');
            $table->index('status', 'ticket_journeys_status_index');
            $table->index('ticket_id', 'ticket_journeys_ticket_id_index');
            $table->index('to_station_id', 'ticket_journeys_to_station_id_index');
            $table->index('trip_id', 'ticket_journeys_trip_id_index');
            $table->index('vehicle_id', 'ticket_journeys_vehicle_id_index');
            $table->unique(['ticket_id', 'direction'], 'uniq_journey_direction_per_ticket');
            $table->foreign('assigned_by', 'fk3_ticket_journeys_assigned_by')
                ->references('id')
                ->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('boarded_by', 'fk3_ticket_journeys_boarded_by')
                ->references('id')
                ->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('departure_schedule_id', 'fk3_ticket_journeys_departure_schedule_id')
                ->references('id')
                ->on('departure_schedules')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('from_station_id', 'fk3_ticket_journeys_from_station_id')
                ->references('id')
                ->on('stations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('ticket_id', 'fk3_ticket_journeys_ticket_id')
                ->references('id')
                ->on('tickets')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('to_station_id', 'fk3_ticket_journeys_to_station_id')
                ->references('id')
                ->on('stations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('trip_id', 'fk3_ticket_journeys_trip_id')
                ->references('id')
                ->on('trips')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('vehicle_id', 'fk3_ticket_journeys_vehicle_id')
                ->references('id')
                ->on('vehicles')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_journeys');
    }
};
