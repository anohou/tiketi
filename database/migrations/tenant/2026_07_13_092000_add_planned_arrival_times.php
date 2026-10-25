<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('planned_arrival_at')->nullable()->after('departure_at');
        });
        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->timestamp('planned_ready_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_connections', fn (Blueprint $table) => $table->dropColumn('planned_ready_at'));
        Schema::table('trips', fn (Blueprint $table) => $table->dropColumn('planned_arrival_at'));
    }
};
