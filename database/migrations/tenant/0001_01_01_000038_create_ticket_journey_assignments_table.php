<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_journey_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_journey_id');
            $table->uuid('previous_trip_id')->nullable();
            $table->uuid('new_trip_id')->nullable();
            $table->integer('previous_seat_number')->nullable();
            $table->integer('new_seat_number')->nullable();
            $table->string('reason')->nullable();
            $table->string('mode')->default('manual');
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('ticket_journey_id', 'ticket_journey_assignments_ticket_journey_id_index');
            $table->foreign('assigned_by', 'fk3_ticket_journey_assignments_assigned_by')
                ->references('id')
                ->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('new_trip_id', 'fk3_ticket_journey_assignments_new_trip_id')
                ->references('id')
                ->on('trips')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('previous_trip_id', 'fk3_ticket_journey_assignments_previous_trip_id')
                ->references('id')
                ->on('trips')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('ticket_journey_id', 'fk3_ticket_journey_assignments_ticket_journey_id')
                ->references('id')
                ->on('ticket_journeys')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_journey_assignments');
    }
};
