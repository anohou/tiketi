<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->index(
                ['trip_id', 'seat_number', 'from_station_id', 'to_station_id'],
                'seat_occupancies_segment_lookup_idx',
            );
            $table->index(
                ['ticket_id', 'trip_id'],
                'seat_occupancies_ticket_trip_idx',
            );
        });

        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->index(
                ['trip_id', 'status'],
                'ticket_connections_trip_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->dropIndex('ticket_connections_trip_status_idx');
        });

        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->dropIndex('seat_occupancies_segment_lookup_idx');
            $table->dropIndex('seat_occupancies_ticket_trip_idx');
        });
    }
};
