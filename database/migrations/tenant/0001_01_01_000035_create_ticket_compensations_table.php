<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_compensations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference');
            $table->uuid('ticket_id');
            $table->uuid('ticket_connection_id')->nullable();
            $table->string('incident_type', 40);
            $table->string('compensation_type', 40);
            $table->bigInteger('amount')->default(0);
            $table->string('status', 30)->default('pending_approval');
            $table->text('reason');
            $table->uuid('requested_by');
            $table->uuid('approved_by')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->uuid('replacement_trip_id')->nullable();
            $table->integer('replacement_seat_number')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->uuid('boarded_by')->nullable();
            $table->index('approved_by', 'ticket_compensations_approved_by_index');
            $table->index('compensation_type', 'ticket_compensations_compensation_type_index');
            $table->index('executed_by', 'ticket_compensations_executed_by_index');
            $table->unique('reference', 'ticket_compensations_reference_unique');
            $table->index('replacement_trip_id', 'ticket_compensations_replacement_trip_id_index');
            $table->index('requested_by', 'ticket_compensations_requested_by_index');
            $table->index('status', 'ticket_compensations_status_index');
            $table->index('ticket_connection_id', 'ticket_compensations_ticket_connection_id_index');
            $table->index('ticket_id', 'ticket_compensations_ticket_id_index');
            $table->foreign('approved_by', 'ticket_compensations_approved_by_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('boarded_by', 'ticket_compensations_boarded_by_foreign')
                ->references('id')
                ->on('crew_members')->nullOnDelete();
            $table->foreign('executed_by', 'ticket_compensations_executed_by_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('replacement_trip_id', 'ticket_compensations_replacement_trip_id_foreign')
                ->references('id')
                ->on('trips')->nullOnDelete();
            $table->foreign('requested_by', 'ticket_compensations_requested_by_foreign')
                ->references('id')
                ->on('users')->restrictOnDelete();
            $table->foreign('ticket_connection_id', 'ticket_compensations_ticket_connection_id_foreign')
                ->references('id')
                ->on('ticket_connections')->nullOnDelete();
            $table->foreign('ticket_id', 'ticket_compensations_ticket_id_foreign')
                ->references('id')
                ->on('tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_compensations');
    }
};
