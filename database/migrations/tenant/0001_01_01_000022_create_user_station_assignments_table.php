<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_station_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('station_id');
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('station_id', 'user_station_assignments_station_id_index');
            $table->index('user_id', 'user_station_assignments_user_id_index');
            $table->unique(['user_id', 'station_id'], 'user_station_assignments_user_id_station_id_unique');
            $table->foreign('station_id', 'user_station_assignments_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('user_id', 'user_station_assignments_user_id_foreign')
                ->references('id')
                ->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_station_assignments');
    }
};
