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
        Schema::create('ticket_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('TSR CI');
            $table->json('phone_numbers')->nullable(); // Array of phone numbers
            $table->json('footer_messages')->nullable(); // Array of footer messages
            $table->string('qr_code_base_url')->nullable();
            $table->boolean('print_qr_code')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_settings');
    }
};
