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
        Schema::table('tickets', function (Blueprint $table) {
            // Drop Foreign Keys first (names might be auto-generated)
            // We assume standard naming: tickets_from_stop_id_foreign
            $table->dropForeign(['from_stop_id']);
            $table->dropForeign(['to_stop_id']);

            // Rename columns
            $table->renameColumn('from_stop_id', 'from_station_id');
            $table->renameColumn('to_stop_id', 'to_station_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            // Add new Foreign Keys
            $table->foreign('from_station_id')->references('id')->on('stations')->onDelete('cascade'); // Or restrict/null?
            $table->foreign('to_station_id')->references('id')->on('stations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['from_station_id']);
            $table->dropForeign(['to_station_id']);

            $table->renameColumn('from_station_id', 'from_stop_id');
            $table->renameColumn('to_station_id', 'to_stop_id');

            // We can't easily restore FKs to 'stops' since 'stops' table might be gone or we don't want to reference it.
            // Leaving without FK restoration for now as 'stops' are deprecated.
        });
    }
};
