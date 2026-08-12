<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('route_id');
            $table->uuid('vehicle_id')->nullable();
            $table->timestamp('departure_at');
            $table->string('status', 30)->default('scheduled');
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('booking_type', 32)->default('seat_assignment');
            $table->enum('sales_control', ['closed', 'open'])->default('closed');
            $table->uuid('origin_station_id')->nullable();
            $table->uuid('destination_station_id')->nullable();
            $table->string('code', 32)->nullable();
            $table->boolean('allows_open_connections')->default(false);
            $table->timestamp('actual_departed_at')->nullable();
            $table->timestamp('estimated_arrival_at')->nullable();
            $table->timestamp('planned_arrival_at')->nullable();
            $table->boolean('automatic_connection_allocation')->nullable();
            $table->boolean('is_replicable')->default(false);
            $table->uuid('departure_schedule_id')->nullable();
            $table->date('service_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->uuid('opened_by')->nullable();
            $table->boolean('sales_ready')->default(false);
            $table->boolean('operational_ready')->default(false);
            $table->integer('planned_capacity_snapshot')->nullable();
            $table->string('vehicle_assignment_policy')->default('require_real_vehicle');
            $table->integer('seat_assignment_version')->default(0);
            $table->timestamp('vehicle_assignment_deferred_at')->nullable();
            $table->uuid('vehicle_assignment_deferred_by')->nullable();
            $table->string('vehicle_assignment_deferred_reason')->nullable();
            $table->index('booking_type', 'trips_booking_type_index');
            $table->index('departure_schedule_id', 'trips_departure_schedule_id_index');
            $table->index('route_id', 'trips_route_id_index');
            $table->index('service_date', 'trips_service_date_index');
            $table->index('status', 'trips_status_index');
            $table->index('vehicle_id', 'trips_vehicle_id_index');
            $table->unique(['departure_schedule_id', 'service_date'], 'uniq_schedule_service_date');
            $table->foreign('departure_schedule_id', 'fk3_trips_departure_schedule_id')
                ->references('id')
                ->on('departure_schedules')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('opened_by', 'fk3_trips_opened_by')
                ->references('id')
                ->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('destination_station_id', 'trips_destination_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('origin_station_id', 'trips_origin_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('route_id', 'trips_route_id_foreign')
                ->references('id')
                ->on('routes')->cascadeOnDelete();
            $table->foreign('vehicle_id', 'trips_vehicle_id_foreign')
                ->references('id')
                ->on('vehicles')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE "trips"
                ADD CONSTRAINT "trips_status_check"
                CHECK ("status" IN ('scheduled', 'boarding', 'departed', 'arrived', 'cancelled'))
            SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE "trips"
                ADD CONSTRAINT "trips_booking_type_check"
                CHECK ("booking_type" IN ('seat_assignment', 'bulk'))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
