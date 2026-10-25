<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trip_id')->index();
            $table->uuid('crew_member_id')->index();
            $table->enum('type', ['text', 'voice'])->default('text')->index();
            $table->text('body')->nullable();
            $table->string('audio_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('id')->on('trips')->cascadeOnDelete();
            $table->foreign('crew_member_id')->references('id')->on('crew_members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_messages');
    }
};
