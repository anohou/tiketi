<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->uuid('departure_schedule_id')->nullable()->index();
            $table->date('service_date')->nullable()->index();
            $table->timestamp('opened_at')->nullable();
            $table->uuid('opened_by')->nullable();
            $table->boolean('sales_ready')->default(false);
            $table->boolean('operational_ready')->default(false);
            $table->unsignedInteger('planned_capacity_snapshot')->nullable();
            // require_real_vehicle | allow_planned_capacity (copié depuis le programme ou la compagnie)
            $table->string('vehicle_assignment_policy')->default('require_real_vehicle');
            $table->unsignedInteger('seat_assignment_version')->default(0);
            $table->timestamp('vehicle_assignment_deferred_at')->nullable();
            $table->uuid('vehicle_assignment_deferred_by')->nullable();
            $table->string('vehicle_assignment_deferred_reason')->nullable();
        });

        // Unicité idempotente : un seul voyage matérialisé par programme et par date.
        // PostgreSQL traite les NULL comme distincts, donc les voyages manuels
        // (departure_schedule_id NULL) ne sont pas concernés par cette contrainte.
        Schema::table('trips', function (Blueprint $table) {
            $table->unique(['departure_schedule_id', 'service_date'], 'uniq_schedule_service_date');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique('uniq_schedule_service_date');
            $table->dropColumn([
                'departure_schedule_id',
                'service_date',
                'opened_at',
                'opened_by',
                'sales_ready',
                'operational_ready',
                'planned_capacity_snapshot',
                'vehicle_assignment_policy',
                'seat_assignment_version',
                'vehicle_assignment_deferred_at',
                'vehicle_assignment_deferred_by',
                'vehicle_assignment_deferred_reason',
            ]);
        });
    }
};
