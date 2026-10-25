<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trip_id');
            $table->uuid('crew_member_id');
            $table->enum('type', ['text', 'voice'])->default('text');
            $table->text('body')->nullable();
            $table->string('audio_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('crew_member_id', 'crew_messages_crew_member_id_index');
            $table->index('trip_id', 'crew_messages_trip_id_index');
            $table->index('type', 'crew_messages_type_index');
            $table->foreign('crew_member_id', 'crew_messages_crew_member_id_foreign')
                ->references('id')
                ->on('crew_members')->cascadeOnDelete();
            $table->foreign('trip_id', 'crew_messages_trip_id_foreign')
                ->references('id')
                ->on('trips')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_messages');
    }
};
