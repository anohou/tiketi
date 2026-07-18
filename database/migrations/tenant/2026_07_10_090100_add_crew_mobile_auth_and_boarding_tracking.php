<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->string('pin')->nullable()->after('license_expiry_date');
            $table->string('push_token')->nullable()->after('pin');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->uuid('seller_id')->nullable()->change();
            $table->foreign('seller_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('boarded_at')->nullable()->after('cancelled_by');
            $table->uuid('boarded_by')->nullable()->after('boarded_at');
            $table->foreign('boarded_by')->references('id')->on('crew_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['boarded_by']);
            $table->dropColumn(['boarded_at', 'boarded_by']);
            $table->dropForeign(['seller_id']);
            $table->uuid('seller_id')->nullable(false)->change();
            $table->foreign('seller_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropColumn(['pin', 'push_token']);
        });
    }
};
