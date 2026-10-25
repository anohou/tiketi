<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_offline_actions', function (Blueprint $table) {
            $table->string('status', 20)->default('confirmed')->after('type')->index();
            $table->char('payload_hash', 64)->nullable()->after('status')->index();
            $table->json('request_payload')->nullable()->after('payload_hash');
            $table->unsignedSmallInteger('attempt_count')->default(1)->after('result');
            $table->string('error_code', 80)->nullable()->after('attempt_count')->index();
            $table->timestamp('processed_at')->nullable()->after('error_code');
            $table->timestamp('expires_at')->nullable()->after('processed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('crew_offline_actions', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'payload_hash',
                'request_payload',
                'attempt_count',
                'error_code',
                'processed_at',
                'expires_at',
            ]);
        });
    }
};
