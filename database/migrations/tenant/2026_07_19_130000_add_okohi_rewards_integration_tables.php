<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create okohi_reward_requests table
        Schema::create('okohi_reward_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('users');
            $table->foreignUuid('trip_id')->constrained('trips');
            $table->foreignUuid('from_station_id')->constrained('stations');
            $table->foreignUuid('to_station_id')->constrained('stations');
            $table->integer('seat_number');
            $table->string('customer_number');
            $table->string('reward_id');
            $table->string('okohi_transaction_id')->nullable()->index();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('pending'); // pending, confirmed, rejected, expired, failed
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // 2. Add okohi fields to tickets table
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('payment_method')->default('cash'); // cash, okohi_reward
            $table->string('okohi_customer_number')->nullable();
            $table->string('okohi_reward_id')->nullable();
            $table->string('okohi_transaction_id')->nullable();
            $table->integer('gross_amount')->nullable();
            $table->integer('discount_amount')->nullable();
            $table->integer('amount_collected')->nullable();
        });

        // 3. Add hold fields to trip_seat_occupancies table
        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->uuid('okohi_reward_request_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable();

            $table->foreign('okohi_reward_request_id')
                ->references('id')
                ->on('okohi_reward_requests')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->dropForeign(['okohi_reward_request_id']);
            $table->dropColumn(['okohi_reward_request_id', 'expires_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'okohi_customer_number',
                'okohi_reward_id',
                'okohi_transaction_id',
                'gross_amount',
                'discount_amount',
                'amount_collected',
            ]);
        });

        Schema::dropIfExists('okohi_reward_requests');
    }
};
