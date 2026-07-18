<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_offline_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crew_member_id')->index();
            $table->uuid('trip_id')->index();
            $table->string('type', 20);
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_offline_actions');
    }
};
