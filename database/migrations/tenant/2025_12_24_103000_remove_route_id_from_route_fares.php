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
        Schema::table('route_fares', function (Blueprint $table) {
            // Drop foreign key first if exists
            $table->dropForeign(['route_id']);
            $table->dropColumn('route_id');
        });

        // Update unique constraint to just from_stop + to_stop
        Schema::table('route_fares', function (Blueprint $table) {
            $table->unique(['from_stop_id', 'to_stop_id'], 'route_fares_from_to_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_fares', function (Blueprint $table) {
            $table->dropUnique('route_fares_from_to_unique');
            $table->uuid('route_id')->nullable()->after('id');
        });
    }
};
