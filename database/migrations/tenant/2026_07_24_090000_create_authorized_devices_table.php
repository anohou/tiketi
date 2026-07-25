<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorized_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('secret_hash', 64);
            $table->string('channel', 20)->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('name')->nullable();
            $table->string('platform')->nullable();
            $table->string('app_version')->nullable();
            $table->string('requested_by_type', 40)->nullable();
            $table->uuid('requested_by_id')->nullable()->index();
            $table->uuid('approved_by_user_id')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
            $table->text('last_user_agent')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('authorized_device_id')->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('authorized_device_id');
        });

        Schema::dropIfExists('authorized_devices');
    }
};
