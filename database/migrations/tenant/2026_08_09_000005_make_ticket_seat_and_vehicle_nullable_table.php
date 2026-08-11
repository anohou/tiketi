<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ventes quantity_only : un billet peut être émis sans siège confirmé
        // (car réel non encore affecté). La place est attribuée après coup par
        // DeferredSeatAllocator. Voir plan §3.5.
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('seat_number')->nullable()->change();
            $table->uuid('vehicle_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('seat_number')->nullable(false)->change();
            $table->uuid('vehicle_id')->nullable(false)->change();
        });
    }
};
