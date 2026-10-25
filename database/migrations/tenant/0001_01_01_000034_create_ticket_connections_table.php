<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('transfer_station_id');
            $table->uuid('destination_station_id');
            $table->uuid('trip_id')->nullable();
            $table->integer('seat_number')->nullable();
            $table->enum('status', ['pending', 'ready', 'assigned', 'boarded', 'completed', 'cancelled', 'missed'])->default('pending');
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->uuid('boarded_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();
            $table->string('assignment_mode', 20)->nullable();
            $table->timestamp('planned_ready_at')->nullable();
            $table->uuid('route_id')->nullable();
            $table->index('assigned_by', 'ticket_connections_assigned_by_index');
            $table->index('boarded_by', 'ticket_connections_boarded_by_index');
            $table->index('destination_station_id', 'ticket_connections_destination_station_id_index');
            $table->index('estimated_ready_at', 'ticket_connections_estimated_ready_at_index');
            $table->index('planned_ready_at', 'ticket_connections_planned_ready_at_index');
            $table->index('route_id', 'ticket_connections_route_id_index');
            $table->index('status', 'ticket_connections_status_index');
            $table->unique('ticket_id', 'ticket_connections_ticket_id_unique');
            $table->index('transfer_station_id', 'ticket_connections_transfer_station_id_index');
            $table->index('trip_id', 'ticket_connections_trip_id_index');
            $table->index(['trip_id', 'status'], 'ticket_connections_trip_status_idx');
            $table->foreign('assigned_by', 'ticket_connections_assigned_by_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('boarded_by', 'ticket_connections_boarded_by_foreign')
                ->references('id')
                ->on('crew_members')->nullOnDelete();
            $table->foreign('destination_station_id', 'ticket_connections_destination_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('route_id', 'ticket_connections_route_id_foreign')
                ->references('id')
                ->on('routes')->nullOnDelete();
            $table->foreign('ticket_id', 'ticket_connections_ticket_id_foreign')
                ->references('id')
                ->on('tickets')->cascadeOnDelete();
            $table->foreign('transfer_station_id', 'ticket_connections_transfer_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('trip_id', 'ticket_connections_trip_id_foreign')
                ->references('id')
                ->on('trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_connections');
    }
};
