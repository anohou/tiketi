<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\TenantDatabaseManagers\SecurePostgreSQLDatabaseManager;
use Illuminate\Console\Command;

class GrantTenantBackupPrivileges extends Command
{
    protected $signature = 'tenants:grant-backup-privileges
                            {--tenant=* : Limit the operation to specific tenant IDs}
                            {--force : Allow execution in production}';

    protected $description = 'Grant the configured backup role read access to existing tenant databases';

    public function handle(SecurePostgreSQLDatabaseManager $databaseManager): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Use --force to grant tenant backup privileges in production.');

            return self::FAILURE;
        }

        $tenantIds = array_values(array_filter(array_map('strval', $this->option('tenant'))));
        $query = Tenant::query()->orderBy('id');
        if ($tenantIds !== []) {
            $query->whereIn('id', $tenantIds);
        }

        $count = 0;
        foreach ($query->cursor() as $tenant) {
            $databaseManager->grantBackupPrivileges($tenant);
            $this->info("Granted tenant backup privileges: {$tenant->id}");
            $count++;
        }

        $this->info("Tenant backup privilege repair complete ({$count} tenant(s)).");

        return self::SUCCESS;
    }
}
