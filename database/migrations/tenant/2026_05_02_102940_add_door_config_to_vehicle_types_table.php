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
            $table->string('door_side')->default('right')->after('door_positions'); // 'left' or 'right'
            $table->unsignedInteger('door_width')->default(2)->after('door_side'); // 1, 2, or 3 slots
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_types', function (Blueprint $table) {
            $table->dropColumn(['door_side', 'door_width']);
        });
    }
};
