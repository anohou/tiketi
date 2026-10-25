<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_vehicle_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('station_id');
            $table->uuid('vehicle_id');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['station_id', 'active', 'valid_from', 'valid_until'], 'station_vehicle_pool_period_idx');
            $table->index(['vehicle_id', 'active', 'valid_from', 'valid_until'], 'vehicle_station_pool_period_idx');
            $table->foreign('station_id', 'station_vehicle_assignments_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('vehicle_id', 'station_vehicle_assignments_vehicle_id_foreign')
                ->references('id')
                ->on('vehicles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_vehicle_assignments');
    }
};
