<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identifier');
            $table->string('maker')->nullable();
            $table->uuid('vehicle_type_id');
            $table->integer('seat_count');
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->json('door_positions')->nullable();
            $table->boolean('active')->default(true);
            $table->string('inactive_reason')->nullable();
            $table->date('insurance_expiry_date')->nullable();
            $table->boolean('is_placeholder')->default(false);
            $table->unique('identifier', 'vehicles_identifier_unique');
            $table->index('vehicle_type_id', 'vehicles_vehicle_type_id_index');
            $table->foreign('vehicle_type_id', 'vehicles_vehicle_type_id_foreign')
                ->references('id')
                ->on('vehicle_types')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
