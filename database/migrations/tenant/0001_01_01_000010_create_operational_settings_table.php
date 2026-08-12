<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->boolean('automatic_connection_allocation')->default(false);
            $table->integer('connection_transfer_buffer_minutes')->default(15);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_settings');
    }
};
