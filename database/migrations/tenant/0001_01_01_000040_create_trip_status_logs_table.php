<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_status_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trip_id');
            $table->string('status', 30);
            $table->uuid('changed_by_user_id')->nullable();
            $table->uuid('changed_by_crew_member_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('status', 'trip_status_logs_status_index');
            $table->foreign('changed_by_crew_member_id', 'trip_status_logs_changed_by_crew_member_id_foreign')
                ->references('id')
                ->on('crew_members')->nullOnDelete();
            $table->foreign('changed_by_user_id', 'trip_status_logs_changed_by_user_id_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('trip_id', 'trip_status_logs_trip_id_foreign')
                ->references('id')
                ->on('trips')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_status_logs');
    }
};
