<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->foreignUuid('origin_destination_id')->nullable()->after('name')->constrained('destinations');
            $table->foreignUuid('target_destination_id')->nullable()->after('origin_destination_id')->constrained('destinations');

            // Make station IDs nullable as they might be inferred or secondary
            $table->uuid('origin_station_id')->nullable()->change();
            $table->uuid('destination_station_id')->nullable()->change();
        });

        // Migrate Data: Infer Destinations from existing Stations
        $routes = DB::table('routes')->get();
        foreach ($routes as $route) {
            $originStation = DB::table('stations')->where('id', $route->origin_station_id)->first();
            $targetStation = DB::table('stations')->where('id', $route->destination_station_id)->first();

            if ($originStation && $originStation->destination_id) {
                DB::table('routes')->where('id', $route->id)->update([
                    'origin_destination_id' => $originStation->destination_id,
                ]);
            }

            if ($targetStation && $targetStation->destination_id) {
                DB::table('routes')->where('id', $route->id)->update([
                    'target_destination_id' => $targetStation->destination_id,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropForeign(['origin_destination_id']);
            $table->dropForeign(['target_destination_id']);
            $table->dropColumn(['origin_destination_id', 'target_destination_id']);

            // Revert nullable not easily possible without ensuring data validity
            // $table->uuid('origin_station_id')->nullable(false)->change();
        });
    }
};
