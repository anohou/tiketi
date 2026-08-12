<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_crew_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id');
            $table->uuid('crew_member_id');
            $table->enum('role', ['driver', 'assistant']);
            $table->timestamp('assigned_from');
            $table->timestamp('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['crew_member_id', 'assigned_from', 'assigned_to'], 'crew_assignments_member_interval_idx');
            $table->index(['vehicle_id', 'role', 'assigned_from', 'assigned_to'], 'crew_assignments_vehicle_role_interval_idx');
            $table->index('crew_member_id', 'vehicle_crew_assignments_crew_member_id_index');
            $table->index('role', 'vehicle_crew_assignments_role_index');
            $table->index('vehicle_id', 'vehicle_crew_assignments_vehicle_id_index');
            $table->foreign('crew_member_id', 'vehicle_crew_assignments_crew_member_id_foreign')
                ->references('id')
                ->on('crew_members')->cascadeOnDelete();
            $table->foreign('vehicle_id', 'vehicle_crew_assignments_vehicle_id_foreign')
                ->references('id')
                ->on('vehicles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_crew_assignments');
    }
};
