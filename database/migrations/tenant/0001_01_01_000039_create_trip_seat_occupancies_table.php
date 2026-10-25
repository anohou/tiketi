<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_seat_occupancies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trip_id');
            $table->integer('seat_number');
            $table->uuid('ticket_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->uuid('from_station_id')->nullable();
            $table->uuid('to_station_id')->nullable();
            $table->uuid('okohi_reward_request_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('ticket_journey_id')->nullable();
            $table->index('ticket_journey_id', 'idx_occupancy_journey');
            $table->index(['trip_id', 'seat_number', 'from_station_id', 'to_station_id'], 'seat_occupancies_segment_lookup_idx');
            $table->index(['ticket_id', 'trip_id'], 'seat_occupancies_ticket_trip_idx');
            $table->index('from_station_id', 'trip_seat_occupancies_from_station_id_index');
            $table->index('okohi_reward_request_id', 'trip_seat_occupancies_okohi_reward_request_id_index');
            $table->index('ticket_id', 'trip_seat_occupancies_ticket_id_index');
            $table->index('ticket_journey_id', 'trip_seat_occupancies_ticket_journey_id_index');
            $table->index('to_station_id', 'trip_seat_occupancies_to_station_id_index');
            $table->index('trip_id', 'trip_seat_occupancies_trip_id_index');
            $table->unique(['trip_id', 'ticket_journey_id'], 'uniq_occupancy_journey_per_trip');
            $table->unique(['trip_id', 'seat_number', 'ticket_id'], 'uniq_trip_seat_ticket');
            $table->foreign('ticket_journey_id', 'fk3_occupancy_journey')
                ->references('id')
                ->on('ticket_journeys')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('from_station_id', 'trip_seat_occupancies_from_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('okohi_reward_request_id', 'trip_seat_occupancies_okohi_reward_request_id_foreign')
                ->references('id')
                ->on('okohi_reward_requests')->nullOnDelete();
            $table->foreign('ticket_id', 'trip_seat_occupancies_ticket_id_foreign')
                ->references('id')
                ->on('tickets')->nullOnDelete();
            $table->foreign('to_station_id', 'trip_seat_occupancies_to_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('trip_id', 'trip_seat_occupancies_trip_id_foreign')
                ->references('id')
                ->on('trips')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_seat_occupancies');
    }
};
