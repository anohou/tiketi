<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('station_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('station_id', 'stops_station_id_index');
            $table->foreign('station_id', 'stops_station_id_foreign')
                ->references('id')
                ->on('stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stops');
    }
};
