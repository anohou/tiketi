<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référence le DROIT DE VOYAGE dans l'occupation physique (point D.4).
 * Additive et nullable : aucune donnée existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trip_seat_occupancies', 'ticket_journey_id')) {
            Schema::table('trip_seat_occupancies', function (Blueprint $table) {
                $table->uuid('ticket_journey_id')->nullable()->index()->after('ticket_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('trip_seat_occupancies', 'ticket_journey_id')) {
            Schema::table('trip_seat_occupancies', function (Blueprint $table) {
                $table->dropColumn('ticket_journey_id');
            });
        }
    }
};
