<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // one_way | round_trip
            $table->string('journey_type')->default('one_way')->index();
            $table->string('public_token')->nullable()->unique();
            $table->unsignedInteger('normal_total_amount')->nullable();
            $table->unsignedInteger('round_trip_discount_amount')->default(0);
            $table->timestamp('return_valid_until')->nullable();
            // pending | delivered | failed | not_requested
            $table->string('okohi_delivery_status')->default('not_requested')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn([
                'journey_type',
                'public_token',
                'normal_total_amount',
                'round_trip_discount_amount',
                'return_valid_until',
                'okohi_delivery_status',
            ]);
        });
    }
};
