<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicle_crew_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id')->index();
            $table->uuid('crew_member_id')->index();
            $table->enum('role', ['driver', 'assistant'])->index();
            $table->dateTime('assigned_from');
            $table->dateTime('assigned_to')->nullable(); // null = affectation en cours
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
            $table->foreign('crew_member_id')->references('id')->on('crew_members')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_crew_assignments');
    }
};
