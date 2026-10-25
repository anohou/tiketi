<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('active');
            $table->boolean('automatic_connection_allocation')->nullable()->after('estimated_duration_minutes');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('estimated_duration_minutes');
        });

        Schema::create('operational_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('automatic_connection_allocation')->default(false);
            $table->unsignedInteger('connection_transfer_buffer_minutes')->default(15);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_settings');
        Schema::table('trips', function (Blueprint $table) {
            $table->unsignedInteger('estimated_duration_minutes')->nullable()->after('departure_at');
        });
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['estimated_duration_minutes', 'automatic_connection_allocation']);
        });
    }
};
