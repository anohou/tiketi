<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_connection_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_connection_id')->index();
            $table->uuid('from_trip_id')->nullable()->index();
            $table->uuid('to_trip_id')->nullable()->index();
            $table->unsignedInteger('from_seat_number')->nullable();
            $table->unsignedInteger('to_seat_number')->nullable();
            $table->string('action', 30)->index();
            $table->string('reason')->nullable();
            $table->uuid('performed_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('ticket_connection_id')->references('id')->on('ticket_connections')->cascadeOnDelete();
            $table->foreign('from_trip_id')->references('id')->on('trips')->nullOnDelete();
            $table->foreign('to_trip_id')->references('id')->on('trips')->nullOnDelete();
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_connection_assignments');
    }
};
