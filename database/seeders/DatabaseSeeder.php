<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Tenant;
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
        $tenant = Tenant::firstOrCreate(['id' => 'test'], [
            'name' => 'Transport CI (Test)',
            'email' => 'admin@test.com',
            'phone' => '+225 0101010101',
        ]);
        
        $tenant->domains()->firstOrCreate(['domain' => 'test.localhost']);
        
        $this->command->info('✅ Test Tenant created.');

        // Initialize Seeding for Test Tenant
        $tenant->run(function () {
             $this->call(TenantSeeder::class);
        });
        
        // 3. Create Demo Tenant (Admin only)
        $demoTenant = Tenant::firstOrCreate(['id' => 'demo'], [
            'name' => 'Transport Demo',
            'email' => 'admin@demo.com',
            'phone' => '+225 0202020202',
        ]);

        $demoTenant->domains()->firstOrCreate(['domain' => 'demo.localhost']);

        $demoTenant->run(function () {
             User::create([
                'name' => 'Admin Demo',
                'email' => 'admin@demo.transport.ci',
                'telephone' => '+225 0909090909',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]);
        });

        $this->command->info('✅ Demo Tenant created (Admin only).');
    }
}
