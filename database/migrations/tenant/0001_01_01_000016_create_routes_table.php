<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('origin_station_id')->nullable();
            $table->uuid('destination_station_id')->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->uuid('origin_destination_id')->nullable();
            $table->uuid('target_destination_id')->nullable();
            $table->integer('estimated_duration_minutes')->nullable();
            $table->boolean('automatic_connection_allocation')->nullable();
            $table->index('destination_station_id', 'routes_destination_station_id_index');
            $table->index('name', 'routes_name_index');
            $table->index('origin_station_id', 'routes_origin_station_id_index');
            $table->foreign('destination_station_id', 'routes_destination_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('origin_destination_id', 'routes_origin_destination_id_foreign')
                ->references('id')
                ->on('destinations');
            $table->foreign('origin_station_id', 'routes_origin_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('target_destination_id', 'routes_target_destination_id_foreign')
                ->references('id')
                ->on('destinations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
