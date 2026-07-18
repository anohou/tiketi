<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_status_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trip_id')->index();
            $table->uuid('crew_member_id')->index();
            $table->enum('status', ['normal', 'traffic_jam', 'accident', 'mechanical_trouble'])->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('reported_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('trips')->cascadeOnDelete();
            $table->foreign('crew_member_id')->references('id')->on('crew_members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_status_reports');
    }
};
