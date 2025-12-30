<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        // Deprecated. Use TenantSeeder.php for tenant data.
        // This file is kept to avoid class not found errors if called directly.
        $this->command->info('⚠️ InitialSetupSeeder is deprecated. Please use TenantSeeder for tenant data.');
    }
}
