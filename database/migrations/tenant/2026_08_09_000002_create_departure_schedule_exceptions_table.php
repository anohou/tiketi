<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_schedule_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('departure_schedule_id')->index();
            $table->date('service_date');
            // cancelled | time_changed | suspended | capacity_changed
            $table->string('type');
            $table->time('replacement_time')->nullable();
            $table->unsignedInteger('replacement_capacity')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['departure_schedule_id', 'service_date'], 'uniq_schedule_exception_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_schedule_exceptions');
    }
};
