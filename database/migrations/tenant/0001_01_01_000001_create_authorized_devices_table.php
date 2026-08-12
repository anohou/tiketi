<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('secret_hash', 64);
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->string('name')->nullable();
            $table->string('platform')->nullable();
            $table->string('app_version')->nullable();
            $table->string('requested_by_type', 40)->nullable();
            $table->uuid('requested_by_id')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->text('last_user_agent')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('approved_by_user_id', 'authorized_devices_approved_by_user_id_index');
            $table->index('channel', 'authorized_devices_channel_index');
            $table->index(['channel', 'status'], 'authorized_devices_channel_status_index');
            $table->index('requested_by_id', 'authorized_devices_requested_by_id_index');
            $table->index('status', 'authorized_devices_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorized_devices');
    }
};
