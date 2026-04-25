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
        $this->setAllowedRoles(['admin', 'supervisor', 'seller', 'accountant', 'executive']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This will fail if any users have 'accountant' or 'executive' role
        // You should first update those users to a valid role before rolling back
        $this->setAllowedRoles(['admin', 'supervisor', 'seller']);
    }

    private function setAllowedRoles(array $roles): void
    {
        $driver = DB::getDriverName();
        $quotedRoles = implode(', ', array_map(fn (string $role) => DB::getPdo()->quote($role), $roles));

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE users MODIFY COLUMN role ENUM(%s) DEFAULT %s',
                $quotedRoles,
                DB::getPdo()->quote('seller'),
            ));

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN role TYPE VARCHAR(255)');
            DB::statement('ALTER TABLE users ALTER COLUMN role SET DEFAULT '.DB::getPdo()->quote('seller'));
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement(sprintf(
                'ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN (%s))',
                $quotedRoles,
            ));

            return;
        }

        // SQLite and other test drivers do not enforce Laravel enum checks here.
    }
};
