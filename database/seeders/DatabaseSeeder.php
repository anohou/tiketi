<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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
                'role' => 'super_admin',
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
                DB::statement("
                    SELECT pg_terminate_backend(pg_stat_activity.pid)
                    FROM pg_stat_activity
                    WHERE pg_stat_activity.datname = '$dbName'
                      AND pid <> pg_backend_pid()
                ");
            }

            // Drop database with FORCE option if supported (PostgreSQL 13+)
            DB::statement("DROP DATABASE IF EXISTS \"$dbName\" WITH (FORCE)");
            $this->command->info("🗑️ Existing tenant database $dbName dropped.");
        } catch (\Exception $e) {
            $this->command->warn('Could not drop database '.$dbName.' with FORCE: '.$e->getMessage());
            try {
                DB::statement("DROP DATABASE IF EXISTS \"$dbName\"");
                $this->command->info('🗑️ Existing tenant database '.$dbName.' dropped (no force).');
            } catch (\Exception $e2) {
                $this->command->warn('Fallback drop also failed: '.$e2->getMessage());
            }
        }

        // Clean up tenant in landlord DB if it already exists to prevent duplicate key errors
        $existingTenant = Tenant::find($tenantId);
        if ($existingTenant) {
            $existingTenant->delete();
            $this->command->info('🗑️ Existing tenant record removed from central database.');
        }

        $tenant = Tenant::create([
            'id' => $tenantId,
            'name' => 'Transport CI (Test)',
            'email' => 'admin@test.com',
            'phone' => '+225 0101010101',
        ]);

        $tenant->domains()->create(['domain' => 'test.localhost']);

        // Check if we can detect the host computer's local IP address to allow mobile connection during development
        try {
            $localIp = null;
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows fallback
                $ipConfig = shell_exec('ipconfig');
                if ($ipConfig && preg_match('/IPv4 Address.*: (192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+)/', $ipConfig, $matches)) {
                    $localIp = $matches[1];
                }
            } else {
                // macOS (Darwin) or Linux
                $localIp = trim((string) shell_exec("ipconfig getifaddr en0 || ipconfig getifaddr en1 || hostname -I | cut -d' ' -f1"));
            }

            if (! empty($localIp) && filter_var($localIp, FILTER_VALIDATE_IP)) {
                $tenant->domains()->firstOrCreate(['domain' => "test.{$localIp}.nip.io"]);
                $this->command->info("📡 Local IP domain test.{$localIp}.nip.io registered in landlord DB.");
            }
        } catch (\Exception $e) {
            $this->command->warn('Could not auto-detect local IP: '.$e->getMessage());
        }

        $this->command->info('✅ Test Tenant created.');

        // Initialize Seeding for Test Tenant
        $tenant->run(function () {
            $this->call(TenantSeeder::class);
        });
    }
}
