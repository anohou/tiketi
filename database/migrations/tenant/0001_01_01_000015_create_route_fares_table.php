<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_fares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_station_id');
            $table->uuid('to_station_id');
            $table->integer('amount');
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_bidirectional')->default(true);
            $table->boolean('active')->default(true);
            $table->index('from_station_id', 'route_fares_from_station_id_index');
            $table->unique(['from_station_id', 'to_station_id'], 'route_fares_from_to_unique');
            $table->index('to_station_id', 'route_fares_to_station_id_index');
            $table->foreign('from_station_id', 'route_fares_from_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('to_station_id', 'route_fares_to_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_fares');
    }
};
