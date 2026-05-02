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
            \Illuminate\Support\Facades\DB::statement("DROP DATABASE IF EXISTS \"$dbName\"");
            $this->command->info("🗑️ Existing tenant database $dbName dropped.");
        } catch (\Exception $e) {
            $this->command->warn("Could not drop database $dbName: ".$e->getMessage());
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
