<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->setAllowedRoles(['admin', 'supervisor', 'seller', 'accountant', 'executive', 'fleet_manager']);

        Schema::create('user_vehicle_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('vehicle_id')->index();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'vehicle_id']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_vehicle_assignments');

        // Restore the previous role set.
        $this->setAllowedRoles(['admin', 'supervisor', 'seller', 'accountant', 'executive']);
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
    }
};
