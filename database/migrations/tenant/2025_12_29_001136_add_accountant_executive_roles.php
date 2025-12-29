<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add 'accountant' and 'executive' roles to the users table enum.
     */
    public function up(): void
    {
        // MySQL: modify the enum to include the new roles
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'supervisor', 'seller', 'accountant', 'executive') DEFAULT 'seller'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This will fail if any users have 'accountant' or 'executive' role
        // You should first update those users to a valid role before rolling back
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'supervisor', 'seller') DEFAULT 'seller'");
    }
};
