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
            $table->text('company_name')->default('TSR CI');
            $table->json('phone_numbers')->nullable(); // Array of phone numbers
            $table->text('cc_label')->nullable();
            $table->json('footer_messages')->nullable(); // Array of footer messages
            $table->text('baggage_policy_message')->nullable();
            $table->json('qr_code_base_url')->nullable();
            $table->boolean('print_qr_code')->default(true);
            $table->json('settings')->nullable();
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
