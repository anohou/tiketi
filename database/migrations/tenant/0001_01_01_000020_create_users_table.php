<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('telephone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'supervisor', 'seller', 'accountant', 'executive', 'fleet_manager'])->default('seller');
            $table->json('settings')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('active')->default(true);
            $table->unique('email', 'users_email_unique');
            $table->index('role', 'users_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
