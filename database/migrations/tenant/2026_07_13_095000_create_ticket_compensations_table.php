<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_compensations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->uuid('ticket_id')->index();
            $table->uuid('ticket_connection_id')->nullable()->index();
            $table->string('incident_type', 40);
            $table->string('compensation_type', 40)->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('status', 30)->default('pending_approval')->index();
            $table->text('reason');
            $table->uuid('requested_by')->index();
            $table->uuid('approved_by')->nullable()->index();
            $table->uuid('executed_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->uuid('replacement_trip_id')->nullable()->index();
            $table->unsignedInteger('replacement_seat_number')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('ticket_connection_id')->references('id')->on('ticket_connections')->nullOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('executed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('replacement_trip_id')->references('id')->on('trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_compensations');
    }
};
