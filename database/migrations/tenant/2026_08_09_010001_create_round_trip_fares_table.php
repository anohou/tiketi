<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('round_trip_fares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('from_station_id')->index();
            $table->uuid('to_station_id')->index();
            $table->unsignedInteger('round_trip_amount');
            // Montants normaux (affichage + historique de l'économie).
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

    public function down(): void
    {
        Schema::dropIfExists('round_trip_fares');
    }
};
