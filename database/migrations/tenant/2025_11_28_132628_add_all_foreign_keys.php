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
        // Add foreign key constraints to users table
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('set null');
        });

        // Add foreign key constraints to routes table
        Schema::table('routes', function (Blueprint $table) {
            $table->foreign('origin_station_id')->references('id')->on('stations')->onDelete('cascade');
            $table->foreign('destination_station_id')->references('id')->on('stations')->onDelete('cascade');
        });

        // Add foreign key constraints to stops table
        Schema::table('stops', function (Blueprint $table) {
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('set null');
        });

        // Add foreign key constraints to route_stop_orders table
        Schema::table('route_stop_orders', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');
            $table->foreign('stop_id')->references('id')->on('stops')->onDelete('cascade');
        });

        // Add foreign key constraints to route_fares table
        Schema::table('route_fares', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');
            $table->foreign('from_stop_id')->references('id')->on('stops')->onDelete('cascade');
            $table->foreign('to_stop_id')->references('id')->on('stops')->onDelete('cascade');
        });

        // Add foreign key constraints to vehicles table
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('cascade');
        });

        // Add foreign key constraints to trips table
        Schema::table('trips', function (Blueprint $table) {
            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
        });

        // Add foreign key constraints to tickets table
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('trip_id')->references('id')->on('trips')->onDelete('cascade');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
            $table->foreign('from_stop_id')->references('id')->on('stops')->onDelete('cascade');
            $table->foreign('to_stop_id')->references('id')->on('stops')->onDelete('cascade');
            $table->foreign('seller_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('set null');
        });

        // Add foreign key constraints to trip_seat_occupancies table
        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->foreign('trip_id')->references('id')->on('trips')->onDelete('cascade');
            $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('set null');
        });

        // Add foreign key constraints to user_route_assignments table
        Schema::table('user_route_assignments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('route_id')->references('id')->on('routes')->onDelete('cascade');
            $table->foreign('station_id')->references('id')->on('stations')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->dropForeign(['origin_station_id']);
            $table->dropForeign(['destination_station_id']);
        });

        Schema::table('stops', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });

        Schema::table('route_stop_orders', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropForeign(['stop_id']);
        });

        Schema::table('route_fares', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropForeign(['from_stop_id']);
            $table->dropForeign(['to_stop_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['vehicle_type_id']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropForeign(['vehicle_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['trip_id']);
            $table->dropForeign(['vehicle_id']);
            $table->dropForeign(['from_stop_id']);
            $table->dropForeign(['to_stop_id']);
            $table->dropForeign(['seller_id']);
            $table->dropForeign(['station_id']);
        });

        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->dropForeign(['trip_id']);
            $table->dropForeign(['ticket_id']);
        });

        Schema::table('user_route_assignments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['route_id']);
            $table->dropForeign(['station_id']);
        });
    }
};
