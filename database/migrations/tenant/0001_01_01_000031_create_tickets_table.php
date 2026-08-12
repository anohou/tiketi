<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('ticket_number');
            $table->uuid('trip_id');
            $table->uuid('vehicle_id')->nullable();
            $table->integer('seat_number')->nullable();
            $table->uuid('from_station_id');
            $table->uuid('to_station_id');
            $table->string('passenger_name');
            $table->string('passenger_phone');
            $table->integer('price');
            $table->uuid('seller_id')->nullable();
            $table->uuid('station_id')->nullable();
            $table->enum('status', ['issued', 'cancelled', 'refunded'])->default('issued');
            $table->json('qr_payload')->nullable();
            $table->string('qr_code')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->smallInteger('boarding_group')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->uuid('boarded_by')->nullable();
            $table->uuid('crew_member_id')->nullable();
            $table->uuid('final_destination_station_id')->nullable();
            $table->uuid('transfer_station_id')->nullable();
            $table->string('payment_method')->default('cash');
            $table->string('okohi_customer_number')->nullable();
            $table->string('okohi_reward_id')->nullable();
            $table->string('okohi_transaction_id')->nullable();
            $table->integer('gross_amount')->nullable();
            $table->integer('discount_amount')->nullable();
            $table->integer('amount_collected')->nullable();
            $table->string('journey_type')->default('one_way');
            $table->string('public_token')->nullable();
            $table->integer('normal_total_amount')->nullable();
            $table->integer('round_trip_discount_amount')->default(0);
            $table->timestamp('return_valid_until')->nullable();
            $table->string('okohi_delivery_status')->default('not_requested');
            $table->index('cancelled_by', 'tickets_cancelled_by_index');
            $table->index('crew_member_id', 'tickets_crew_member_id_index');
            $table->index('final_destination_station_id', 'tickets_final_destination_station_id_index');
            $table->index('from_station_id', 'tickets_from_station_id_index');
            $table->index('journey_type', 'tickets_journey_type_index');
            $table->index('okohi_delivery_status', 'tickets_okohi_delivery_status_index');
            $table->unique('public_token', 'tickets_public_token_unique');
            $table->index('seat_number', 'tickets_seat_number_index');
            $table->index('seller_id', 'tickets_seller_id_index');
            $table->index('station_id', 'tickets_station_id_index');
            $table->index('status', 'tickets_status_index');
            $table->unique('ticket_number', 'tickets_ticket_number_unique');
            $table->index('to_station_id', 'tickets_to_station_id_index');
            $table->index('transfer_station_id', 'tickets_transfer_station_id_index');
            $table->index('trip_id', 'tickets_trip_id_index');
            $table->index('vehicle_id', 'tickets_vehicle_id_index');
            $table->foreign('boarded_by', 'tickets_boarded_by_foreign')
                ->references('id')
                ->on('crew_members')->nullOnDelete();
            $table->foreign('cancelled_by', 'tickets_cancelled_by_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('crew_member_id', 'tickets_crew_member_id_foreign')
                ->references('id')
                ->on('crew_members')->nullOnDelete();
            $table->foreign('final_destination_station_id', 'tickets_final_destination_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('from_station_id', 'tickets_from_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('seller_id', 'tickets_seller_id_foreign')
                ->references('id')
                ->on('users')->nullOnDelete();
            $table->foreign('station_id', 'tickets_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('to_station_id', 'tickets_to_station_id_foreign')
                ->references('id')
                ->on('stations')->cascadeOnDelete();
            $table->foreign('transfer_station_id', 'tickets_transfer_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
            $table->foreign('trip_id', 'tickets_trip_id_foreign')
                ->references('id')
                ->on('trips')->cascadeOnDelete();
            $table->foreign('vehicle_id', 'tickets_vehicle_id_foreign')
                ->references('id')
                ->on('vehicles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
