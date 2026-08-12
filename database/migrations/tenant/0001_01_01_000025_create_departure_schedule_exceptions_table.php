<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departure_schedule_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('departure_schedule_id');
            $table->date('service_date');
            $table->string('type');
            $table->time('replacement_time')->nullable();
            $table->integer('replacement_capacity')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('departure_schedule_id', 'departure_schedule_exceptions_departure_schedule_id_index');
            $table->unique(['departure_schedule_id', 'service_date'], 'uniq_schedule_exception_date');
            $table->foreign('created_by', 'fk3_departure_schedule_exceptions_created_by')
                ->references('id')
                ->on('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreign('departure_schedule_id', 'fk3_departure_schedule_exceptions_departure_schedule_id')
                ->references('id')
                ->on('departure_schedules')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departure_schedule_exceptions');
    }
};
