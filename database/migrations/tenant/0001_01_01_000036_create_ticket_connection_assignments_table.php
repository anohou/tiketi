<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_connection_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_connection_id');
            $table->uuid('from_trip_id')->nullable();
            $table->uuid('to_trip_id')->nullable();
            $table->integer('from_seat_number')->nullable();
            $table->integer('to_seat_number')->nullable();
            $table->string('action', 30);
            $table->string('reason')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('action', 'ticket_connection_assignments_action_index');
            $table->index('from_trip_id', 'ticket_connection_assignments_from_trip_id_index');
            $table->index('performed_by', 'ticket_connection_assignments_performed_by_index');
            $table->index('ticket_connection_id', 'ticket_connection_assignments_ticket_connection_id_index');
            $table->index('to_trip_id', 'ticket_connection_assignments_to_trip_id_index');
            $table->foreign('from_trip_id', 'ticket_connection_assignments_from_trip_id_foreign')
                ->references('id')
                ->on('trips')->nullOnDelete();
            $table->foreign('performed_by', 'ticket_connection_assignments_performed_by_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('ticket_connection_id', 'ticket_connection_assignments_ticket_connection_id_foreign')
                ->references('id')
                ->on('ticket_connections')->cascadeOnDelete();
            $table->foreign('to_trip_id', 'ticket_connection_assignments_to_trip_id_foreign')
                ->references('id')
                ->on('trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_connection_assignments');
    }
};
