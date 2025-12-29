<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform Admin Seeder
 * 
 * Creates a superadmin user for the central (landlord) database.
 * This user can log in to the central domain and manage tenants.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        \DB::table('users')->insert([
            'id' => Str::uuid(),
            'name' => 'Platform Admin',
            'email' => 'admin@transport.ci',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $this->command->info('Platform admin created:');
        $this->command->info('  Email: admin@transport.ci');
        $this->command->info('  Password: password');
    }
}
