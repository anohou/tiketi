<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->setAllowedRoles(['superadmin', 'super_admin', 'admin']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'superadmin']);
        $this->setAllowedRoles(['superadmin', 'admin']);
    }

    /** @param list<string> $roles */
    private function setAllowedRoles(array $roles): void
    {
        $driver = DB::getDriverName();
        $quotedRoles = implode(', ', array_map(
            static fn (string $role): string => DB::getPdo()->quote($role),
            $roles,
        ));

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM({$quotedRoles}) NOT NULL DEFAULT 'admin'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ({$quotedRoles}))");
        } elseif ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) use ($roles): void {
                $table->enum('role', $roles)->default('admin')->change();
            });
        }
    }
};
