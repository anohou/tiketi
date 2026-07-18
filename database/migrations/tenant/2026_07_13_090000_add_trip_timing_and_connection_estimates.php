<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('departure_at');
            $table->timestamp('actual_departed_at')->nullable()->after('estimated_duration_minutes');
            $table->timestamp('estimated_arrival_at')->nullable()->after('actual_departed_at');
        });

        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->timestamp('estimated_ready_at')->nullable()->after('status')->index();
            $table->string('assignment_mode', 20)->nullable()->after('assigned_by');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_connections', function (Blueprint $table) {
            $table->dropColumn(['estimated_ready_at', 'assignment_mode']);
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['estimated_duration_minutes', 'actual_departed_at', 'estimated_arrival_at']);
        });
    }
};
