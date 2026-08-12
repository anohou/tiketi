<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_status_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trip_id');
            $table->uuid('crew_member_id');
            $table->enum('status', ['normal', 'traffic_jam', 'accident', 'mechanical_trouble']);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('crew_member_id', 'crew_status_reports_crew_member_id_index');
            $table->index('reported_at', 'crew_status_reports_reported_at_index');
            $table->index('status', 'crew_status_reports_status_index');
            $table->index('trip_id', 'crew_status_reports_trip_id_index');
            $table->foreign('crew_member_id', 'crew_status_reports_crew_member_id_foreign')
                ->references('id')
                ->on('crew_members')->cascadeOnDelete();
            $table->foreign('trip_id', 'crew_status_reports_trip_id_foreign')
                ->references('id')
                ->on('trips')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_status_reports');
    }
};
