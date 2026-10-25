<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->uuid('crew_member_id')->nullable()->after('seller_id')->index();
            $table->foreign('crew_member_id')->references('id')->on('crew_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['crew_member_id']);
            $table->dropColumn('crew_member_id');
        });
    }
};
