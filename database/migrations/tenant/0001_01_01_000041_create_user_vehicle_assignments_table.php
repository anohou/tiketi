<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_vehicle_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('vehicle_id');
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('user_id', 'user_vehicle_assignments_user_id_index');
            $table->unique(['user_id', 'vehicle_id'], 'user_vehicle_assignments_user_id_vehicle_id_unique');
            $table->index('vehicle_id', 'user_vehicle_assignments_vehicle_id_index');
            $table->foreign('user_id', 'user_vehicle_assignments_user_id_foreign')
                ->references('id')
                ->on('users')->cascadeOnDelete();
            $table->foreign('vehicle_id', 'user_vehicle_assignments_vehicle_id_foreign')
                ->references('id')
                ->on('vehicles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_vehicle_assignments');
    }
};
