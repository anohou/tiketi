<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_route_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('route_id');
            $table->uuid('station_id')->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('route_id', 'user_route_assignments_route_id_index');
            $table->index('station_id', 'user_route_assignments_station_id_index');
            $table->index('user_id', 'user_route_assignments_user_id_index');
            $table->unique(['user_id', 'route_id', 'station_id'], 'user_route_assignments_user_id_route_id_station_id_unique');
            $table->foreign('route_id', 'user_route_assignments_route_id_foreign')
                ->references('id')
                ->on('routes')->cascadeOnDelete();
            $table->foreign('station_id', 'user_route_assignments_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('user_id', 'user_route_assignments_user_id_foreign')
                ->references('id')
                ->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_route_assignments');
    }
};
