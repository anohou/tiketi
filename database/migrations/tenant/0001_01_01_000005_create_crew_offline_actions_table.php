<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_offline_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('crew_member_id');
            $table->uuid('trip_id');
            $table->string('type', 20);
            $table->json('result');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('status', 20)->default('confirmed');
            $table->char('payload_hash', 64)->nullable();
            $table->json('request_payload')->nullable();
            $table->smallInteger('attempt_count')->default(1);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->index('crew_member_id', 'crew_offline_actions_crew_member_id_index');
            $table->index('error_code', 'crew_offline_actions_error_code_index');
            $table->index('expires_at', 'crew_offline_actions_expires_at_index');
            $table->index('payload_hash', 'crew_offline_actions_payload_hash_index');
            $table->index('status', 'crew_offline_actions_status_index');
            $table->index('trip_id', 'crew_offline_actions_trip_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_offline_actions');
    }
};
