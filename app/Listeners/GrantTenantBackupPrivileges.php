<?php

declare(strict_types=1);

namespace App\Listeners;

use App\TenantDatabaseManagers\SecurePostgreSQLDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Events\DatabaseMigrated;

class GrantTenantBackupPrivileges
{
    public function __construct(private readonly SecurePostgreSQLDatabaseManager $databaseManager) {}

    public function handle(DatabaseMigrated $event): void
    {
        if (! $event->tenant instanceof TenantWithDatabase) {
            return;
        }

        $this->databaseManager->grantBackupPrivileges($event->tenant);
    }
}
