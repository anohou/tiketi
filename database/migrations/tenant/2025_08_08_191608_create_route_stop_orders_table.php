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
        Schema::create('route_stop_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_id')->index();
            $table->uuid('stop_id')->index();
            $table->unsignedInteger('stop_index'); // 0..n
            $table->timestamps();

            $table->unique(['route_id','stop_index']);
            $table->unique(['route_id','stop_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_stop_orders');
    }
};
