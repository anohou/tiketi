<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('allows_open_connections')->default(false)->after('sales_control');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->uuid('final_destination_station_id')->nullable()->after('to_station_id')->index();
            $table->uuid('transfer_station_id')->nullable()->after('final_destination_station_id')->index();
            $table->foreign('final_destination_station_id')->references('id')->on('stations')->nullOnDelete();
            $table->foreign('transfer_station_id')->references('id')->on('stations')->nullOnDelete();
        });

        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->uuid('from_station_id')->nullable()->after('ticket_id')->index();
            $table->uuid('to_station_id')->nullable()->after('from_station_id')->index();
            $table->foreign('from_station_id')->references('id')->on('stations')->nullOnDelete();
            $table->foreign('to_station_id')->references('id')->on('stations')->nullOnDelete();
        });

        Schema::create('ticket_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->unique();
            $table->uuid('transfer_station_id')->index();
            $table->uuid('destination_station_id')->index();
            $table->uuid('trip_id')->nullable()->index();
            $table->unsignedInteger('seat_number')->nullable();
            $table->enum('status', ['pending', 'ready', 'assigned', 'boarded', 'completed', 'cancelled', 'missed'])->default('pending')->index();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->uuid('assigned_by')->nullable()->index();
            $table->timestamp('boarded_at')->nullable();
            $table->uuid('boarded_by')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('transfer_station_id')->references('id')->on('stations')->cascadeOnDelete();
            $table->foreign('destination_station_id')->references('id')->on('stations')->cascadeOnDelete();
            $table->foreign('trip_id')->references('id')->on('trips')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('boarded_by')->references('id')->on('crew_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_connections');

        Schema::table('trip_seat_occupancies', function (Blueprint $table) {
            $table->dropForeign(['from_station_id']);
            $table->dropForeign(['to_station_id']);
            $table->dropColumn(['from_station_id', 'to_station_id']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['final_destination_station_id']);
            $table->dropForeign(['transfer_station_id']);
            $table->dropColumn(['final_destination_station_id', 'transfer_station_id']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('allows_open_connections');
        });
    }
};
