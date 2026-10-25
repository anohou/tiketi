<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('station_id')->index();
            $table->uuid('route_id')->index();
            $table->uuid('origin_station_id')->index();
            $table->uuid('destination_station_id')->index();
            $table->time('departure_time');
            $table->json('days_of_week'); // [1..7], 1 = lundi
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('timezone')->default(config('app.timezone', 'UTC'));
            $table->unsignedInteger('planned_capacity')->nullable();
            $table->unsignedInteger('confirmed_return_quota')->nullable();
            $table->uuid('default_vehicle_type_id')->index();
            // require_real_vehicle | allow_planned_capacity (null = hérite du paramètre compagnie)
            $table->string('vehicle_assignment_policy')->nullable();
            $table->string('booking_type')->default('seat_assignment');
            $table->string('sales_control')->default('open');
            $table->boolean('allows_open_connections')->default(false);
            $table->boolean('automatic_connection_allocation')->default(false);
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_schedules');
    }
};
