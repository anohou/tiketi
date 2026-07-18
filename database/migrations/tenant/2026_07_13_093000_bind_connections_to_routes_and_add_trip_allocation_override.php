<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->uuid('route_id')->nullable()->after('destination_station_id')->index();
            $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('automatic_connection_allocation')->nullable()->after('allows_open_connections');
        });
    }

    public function down(): void
    {
        Schema::table('trips', fn (Blueprint $table) => $table->dropColumn('automatic_connection_allocation'));
        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropColumn('route_id');
        });
    }
};
