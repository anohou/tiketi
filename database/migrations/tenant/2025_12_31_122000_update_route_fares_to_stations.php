<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('route_fares', function (Blueprint $table) {
            // Drop Foreign keys (stop_id)
            $table->dropForeign(['from_stop_id']);
            $table->dropForeign(['to_stop_id']);
            
            // Rename columns
            $table->renameColumn('from_stop_id', 'from_station_id');
            $table->renameColumn('to_stop_id', 'to_station_id');
        });

        Schema::table('route_fares', function (Blueprint $table) {
            // Add new Foreign keys (station_id)
            // Note: Since stops have been converted to stations with same IDs, existing IDs in data are valid station UUIDs!
            $table->foreign('from_station_id')->references('id')->on('stations')->cascadeOnDelete();
            $table->foreign('to_station_id')->references('id')->on('stations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_fares', function (Blueprint $table) {
            $table->dropForeign(['from_station_id']);
            $table->dropForeign(['to_station_id']);
            
            $table->renameColumn('from_station_id', 'from_stop_id');
            $table->renameColumn('to_station_id', 'to_stop_id');
            
            // Re-adding stops foreign key is tricky if stops table is gone... 
            // skipping exact FK restoration in down.
        });
    }
};
