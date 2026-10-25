<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stop_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('route_id');
            $table->uuid('station_id');
            $table->integer('stop_index');
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('route_id', 'route_stop_orders_route_id_index');
            $table->unique(['route_id', 'station_id'], 'route_stop_orders_route_station_unique');
            $table->unique(['route_id', 'stop_index'], 'route_stop_orders_route_id_stop_index_unique');
            $table->index('station_id', 'route_stop_orders_station_id_index');
            $table->foreign('route_id', 'route_stop_orders_route_id_foreign')
                ->references('id')
                ->on('routes')->cascadeOnDelete();
            $table->foreign('station_id', 'route_stop_orders_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stop_orders');
    }
};
