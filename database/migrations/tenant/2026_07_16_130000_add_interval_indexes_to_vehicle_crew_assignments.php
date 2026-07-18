<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_crew_assignments', function (Blueprint $table) {
            $table->index(
                ['crew_member_id', 'assigned_from', 'assigned_to'],
                'crew_assignments_member_interval_idx'
            );
            $table->index(
                ['vehicle_id', 'role', 'assigned_from', 'assigned_to'],
                'crew_assignments_vehicle_role_interval_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_crew_assignments', function (Blueprint $table) {
            $table->dropIndex('crew_assignments_member_interval_idx');
            $table->dropIndex('crew_assignments_vehicle_role_interval_idx');
        });
    }
};
