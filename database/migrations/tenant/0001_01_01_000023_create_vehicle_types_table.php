<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->json('seat_map');
            $table->integer('seat_count');
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('svg_template_path')->nullable();
            $table->string('seat_configuration')->nullable();
            $table->integer('door_count')->default(1);
            $table->json('door_positions')->nullable();
            $table->integer('last_row_seats')->nullable();
            $table->string('door_side')->default('right');
            $table->integer('door_width')->default(2);
            $table->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
