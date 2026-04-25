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
        Schema::table('trips', function (Blueprint $table) {
            // Store the actual direction of this trip
            // This allows a trip on "Abidjan -> Bondoukou" route to be created as "Bondoukou -> Abidjan"
            $table->uuid('origin_station_id')->nullable()->after('route_id');
            $table->uuid('destination_station_id')->nullable()->after('origin_station_id');

            $table->foreign('origin_station_id')->references('id')->on('stations')->onDelete('set null');
            $table->foreign('destination_station_id')->references('id')->on('stations')->onDelete('set null');
        });

        // Backfill existing trips with origin/destination from their route
        $trips = \App\Models\Trip::with('route')->get();
        foreach ($trips as $trip) {
            if ($trip->route) {
                $trip->origin_station_id = $trip->route->origin_station_id;
                $trip->destination_station_id = $trip->route->destination_station_id;
                $trip->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['origin_station_id']);
            $table->dropForeign(['destination_station_id']);
            $table->dropColumn(['origin_station_id', 'destination_station_id']);
        });
    }
};
