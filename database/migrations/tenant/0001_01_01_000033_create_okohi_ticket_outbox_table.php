<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okohi_ticket_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->string('external_ticket_id');
            $table->string('status')->default('pending');
            $table->string('operation')->default('create');
            $table->integer('version')->default(1);
            $table->string('idempotency_key');
            $table->json('payload')->nullable();
            $table->smallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->json('last_response')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('external_ticket_id', 'okohi_ticket_outbox_external_ticket_id_index');
            $table->unique('idempotency_key', 'okohi_ticket_outbox_idempotency_key_unique');
            $table->index('last_error_code', 'okohi_ticket_outbox_last_error_code_index');
            $table->index('next_attempt_at', 'okohi_ticket_outbox_next_attempt_at_index');
            $table->index('status', 'okohi_ticket_outbox_status_index');
            $table->index('ticket_id', 'okohi_ticket_outbox_ticket_id_index');
            $table->foreign('ticket_id', 'fk3_okohi_ticket_outbox_ticket_id')
                ->references('id')
                ->on('tickets')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okohi_ticket_outbox');
    }
};
