<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okohi_ticket_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->index();
            $table->string('external_ticket_id')->index();
            // pending | delivered | failed | cancelled
            $table->string('status')->default('pending')->index();
            // create | update
            $table->string('operation')->default('create');
            $table->unsignedInteger('version')->default(1);
            $table->string('idempotency_key')->unique();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('last_error_code', 80)->nullable()->index();
            $table->json('last_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okohi_ticket_outbox');
    }
};
