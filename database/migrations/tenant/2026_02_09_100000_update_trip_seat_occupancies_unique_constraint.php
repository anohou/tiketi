<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modifie la contrainte d'unicité de trip_seat_occupancies pour supporter
 * le mode semi-intelligent où un même siège peut être occupé par plusieurs
 * passagers sur des segments non-chevauchants du même voyage.
 *
 * Ancien : unique(trip_id, seat_number) — empêche toute réutilisation
 * Nouveau : unique(trip_id, seat_number, ticket_id) — permet plusieurs tickets par siège
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->dropUnique('uniq_trip_seat');
            $table->unique(['trip_id', 'seat_number', 'ticket_id'], 'uniq_trip_seat_ticket');
        });
    }

    public function down(): void
    {
        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->dropUnique('uniq_trip_seat_ticket');
            $table->unique(['trip_id', 'seat_number'], 'uniq_trip_seat');
        });
    }
};
