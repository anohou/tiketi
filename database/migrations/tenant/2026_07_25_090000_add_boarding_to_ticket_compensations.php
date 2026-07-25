<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_compensations', function (Blueprint $table) {
            $table->timestamp('boarded_at')->nullable()->after('replacement_seat_number');
            $table->uuid('boarded_by')->nullable()->after('boarded_at');
            $table->foreign('boarded_by')->references('id')->on('crew_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_compensations', function (Blueprint $table) {
            $table->dropForeign(['boarded_by']);
            $table->dropColumn(['boarded_at', 'boarded_by']);
        });
    }
};
