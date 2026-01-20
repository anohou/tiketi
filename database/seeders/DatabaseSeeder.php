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
        // Clean up previous runs if they exist
        $existingTenant = Tenant::find('test');
        if ($existingTenant) {
            $existingTenant->delete();
        }


        // Ensure DB is gone (handled by model deletion usually)
        // \Illuminate\Support\Facades\DB::statement("DROP DATABASE IF EXISTS t_test");

        $tenant = Tenant::create([
            'id' => 'test',
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
