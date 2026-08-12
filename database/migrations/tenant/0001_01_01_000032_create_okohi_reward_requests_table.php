<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('okohi_reward_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->uuid('trip_id');
            $table->uuid('from_station_id');
            $table->uuid('to_station_id');
            $table->integer('seat_number');
            $table->string('customer_number');
            $table->string('reward_id');
            $table->string('okohi_transaction_id')->nullable();
            $table->string('idempotency_key');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->uuid('ticket_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('idempotency_key', 'okohi_reward_requests_idempotency_key_unique');
            $table->index('okohi_transaction_id', 'okohi_reward_requests_okohi_transaction_id_index');
            $table->foreign('from_station_id', 'okohi_reward_requests_from_station_id_foreign')
                ->references('id')
                ->on('stations');
            $table->foreign('seller_id', 'okohi_reward_requests_seller_id_foreign')
                ->references('id')
                ->on('users');
            $table->foreign('ticket_id', 'okohi_reward_requests_ticket_id_foreign')
                ->references('id')
                ->on('tickets');
            $table->foreign('to_station_id', 'okohi_reward_requests_to_station_id_foreign')
                ->references('id')
                ->on('stations');
            $table->foreign('trip_id', 'okohi_reward_requests_trip_id_foreign')
                ->references('id')
                ->on('trips');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('okohi_reward_requests');
    }
};
