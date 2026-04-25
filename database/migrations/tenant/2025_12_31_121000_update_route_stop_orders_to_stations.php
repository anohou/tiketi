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
        Schema::table('route_stop_orders', function (Blueprint $table) {
            // Drop old foreign key if it exists (Laravel naming convention)
            // Note: Adjust 'stops_stop_id_foreign' or similar based on actual constraint name if known, but generic handling is safer if unknown.
            // Since we are in a tenant migration, we'll try to be safe.

            // Assuming standard FK name
            $table->dropForeign(['stop_id']);

            // Rename column
            $table->renameColumn('stop_id', 'station_id');
        });

        Schema::table('route_stop_orders', function (Blueprint $table) {
            // Add new foreign key pointing to stations
            $table->foreign('station_id')
                ->references('id')
                ->on('stations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_stop_orders', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
            $table->renameColumn('station_id', 'stop_id');
        });

        Schema::table('route_stop_orders', function (Blueprint $table) {
            $table->foreign('stop_id')
                ->references('id')
                ->on('stops')
                ->cascadeOnDelete();
        });
    }
};
