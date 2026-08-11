<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait des forfaits aller-retour par paire de gares.
 *
 * La remise globale (montant fixe en FCFA) est gérée dans les réglages
 * d'exploitation (OperationalSetting::roundTripDiscountAmount()). La table
 * round_trip_fares devient obsolète et ses données sont supprimées.
 *
 * Aucune autre table ne référence round_trip_fares par clé étrangère : la
 * suppression directe est donc sûre (PostgreSQL dépose les FK portées par la
 * table elle-même).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('round_trip_fares');
    }

    public function down(): void
    {
        Schema::create('round_trip_fares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('from_station_id')->index();
            $table->uuid('to_station_id')->index();
            $table->unsignedInteger('round_trip_amount');
            $table->unsignedInteger('normal_outbound_amount')->nullable();
            $table->unsignedInteger('normal_return_amount')->nullable();
            $table->unsignedInteger('default_validity_days')->default(30);
            $table->boolean('allows_fixed_schedule')->default(true);
            $table->boolean('allows_date_flexible')->default(true);
            $table->boolean('allows_open_return')->default(true);
            $table->boolean('active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['from_station_id', 'to_station_id'], 'uniq_round_trip_pair');
        });
    }
};
