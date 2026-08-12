<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->text('company_name')->default('TEST TRANSPORT');
            $table->json('phone_numbers')->nullable();
            $table->text('cc_label')->nullable();
            $table->json('footer_messages')->nullable();
            $table->text('baggage_policy_message')->nullable();
            $table->json('qr_code_base_url')->nullable();
            $table->boolean('print_qr_code')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->text('okohi_integration_url')->nullable();
            $table->string('okohi_integration_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_settings');
    }
};
