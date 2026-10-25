<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->enum('role', ['driver', 'assistant']);
            $table->string('license_number')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->date('license_expiry_date')->nullable();
            $table->string('pin')->nullable();
            $table->string('push_token')->nullable();
            $table->unique('phone', 'crew_members_phone_unique');
            $table->index('role', 'crew_members_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_members');
    }
};
