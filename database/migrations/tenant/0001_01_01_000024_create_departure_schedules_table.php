<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('station_id');
            $table->uuid('route_id');
            $table->uuid('origin_station_id');
            $table->uuid('destination_station_id');
            $table->time('departure_time');
            $table->json('days_of_week');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('timezone')->default('UTC');
            $table->integer('planned_capacity')->nullable();
            $table->integer('confirmed_return_quota')->nullable();
            $table->uuid('default_vehicle_type_id');
            $table->string('vehicle_assignment_policy')->nullable();
            $table->string('booking_type')->default('seat_assignment');
            $table->string('sales_control')->default('open');
            $table->boolean('allows_open_connections')->default(false);
            $table->boolean('automatic_connection_allocation')->default(false);
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('default_vehicle_type_id', 'departure_schedules_default_vehicle_type_id_index');
            $table->index('destination_station_id', 'departure_schedules_destination_station_id_index');
            $table->index('origin_station_id', 'departure_schedules_origin_station_id_index');
            $table->index('route_id', 'departure_schedules_route_id_index');
            $table->index('station_id', 'departure_schedules_station_id_index');
            $table->foreign('created_by', 'fk3_departure_schedules_created_by')
                ->references('id')
                ->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('default_vehicle_type_id', 'fk3_departure_schedules_default_vehicle_type_id')
                ->references('id')
                ->on('vehicle_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('destination_station_id', 'fk3_departure_schedules_destination_station_id')
                ->references('id')
                ->on('stations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('origin_station_id', 'fk3_departure_schedules_origin_station_id')
                ->references('id')
                ->on('stations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('route_id', 'fk3_departure_schedules_route_id')
                ->references('id')
                ->on('routes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('station_id', 'fk3_departure_schedules_station_id')
                ->references('id')
                ->on('stations')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_schedules');
    }
};
