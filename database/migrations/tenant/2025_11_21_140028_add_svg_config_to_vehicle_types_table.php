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
        Schema::table('vehicle_types', function (Blueprint $table) {
            $table->string('svg_template_path')->nullable()->after('seat_count');
            $table->string('seat_configuration')->nullable()->after('svg_template_path'); // ex: "2+1", "2+2"
            $table->unsignedInteger('door_count')->default(1)->after('seat_configuration');
            $table->json('door_positions')->nullable()->after('door_count'); // ex: [1, 16, 30]
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            $table->dropColumn(['svg_template_path', 'seat_configuration', 'door_count', 'door_positions']);
        });
    }
};
