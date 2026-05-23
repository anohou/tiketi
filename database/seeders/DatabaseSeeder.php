<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Landlord Admin
        User::firstOrCreate(
            ['email' => 'admin@transport.ci'],
            [
                'name' => 'Landlord Admin',
                'password' => Hash::make('password'),
                'role' => 'superadmin',
                'active' => true,
            ]
        );
        $this->command->info('✅ Landlord Admin created.');

        // 2. Create Test Tenant (Fully Seeded)
        $tenantId = 'test';
        $dbName = config('tenancy.database.prefix').$tenantId.config('tenancy.database.suffix');

        // Drop the database if it exists (since migrate:fresh only clears central DB)
        try {
            // First, try to terminate other connections if pgsql
            if (config('database.default') === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement("
                    SELECT pg_terminate_backend(pg_stat_activity.pid)
                    FROM pg_stat_activity
                    WHERE pg_stat_activity.datname = '$dbName'
                      AND pid <> pg_backend_pid()
                ");
            }
            
            // Drop database with FORCE option if supported (PostgreSQL 13+)
            \Illuminate\Support\Facades\DB::statement("DROP DATABASE IF EXISTS \"$dbName\" WITH (FORCE)");
            $this->command->info("🗑️ Existing tenant database $dbName dropped.");
        } catch (\Exception $e) {
            $this->command->warn("Could not drop database $dbName with FORCE: ".$e->getMessage());
            try {
                \Illuminate\Support\Facades\DB::statement("DROP DATABASE IF EXISTS \"$dbName\"");
                $this->command->info("🗑️ Existing tenant database $dbName dropped (no force).");
            } catch (\Exception $e2) {
                $this->command->warn("Fallback drop also failed: ".$e2->getMessage());
            }
        }

        $tenant = Tenant::create([
            'id' => $tenantId,
            'name' => 'Transport CI (Test)',
            'email' => 'admin@test.com',
            'phone' => '+225 0101010101',
        ]);

        $tenant->domains()->create(['domain' => 'test.localhost']);

        $this->command->info('✅ Test Tenant created.');

        // Initialize Seeding for Test Tenant
        $tenant->run(function () {
            $this->call(TenantSeeder::class);
        });
    }
}
