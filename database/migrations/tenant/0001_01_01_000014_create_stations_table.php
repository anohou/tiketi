<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->uuid('destination_id')->nullable();
            $table->unique('code', 'stations_code_unique');
            $table->index('name', 'stations_name_index');
            $table->foreign('destination_id', 'stations_destination_id_foreign')
                ->references('id')
                ->on('destinations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
