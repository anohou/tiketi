<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantDatabaseReconciler;
use App\TenantDatabaseManagers\SecurePostgreSQLDatabaseManager;
use Illuminate\Console\Command;

class ReconcileTenantDatabases extends Command
{
    protected $signature = 'tenants:reconcile-databases
                            {--json : Emit a machine-readable reconciliation report}
                            {--allow-drift : Return success even when missing or orphaned databases are found}';

    protected $description = 'Read-only comparison of central tenant records and physical tenant databases';

    public function handle(
        SecurePostgreSQLDatabaseManager $databaseManager,
        TenantDatabaseReconciler $reconciler,
    ): int {
        $prefix = config('tenancy.database.prefix');
        $suffix = config('tenancy.database.suffix', '');
        if (! is_string($prefix) || $prefix === '' || ! is_string($suffix)) {
            $this->error('Tenant database prefix/suffix configuration is invalid.');

            return self::FAILURE;
        }

        $tenantMap = Tenant::query()->orderBy('id')->pluck('id')->mapWithKeys(
            static fn (mixed $id): array => [$prefix.(string) $id.$suffix => (string) $id],
        );
        $classification = $reconciler->classify(
            $tenantMap->keys()->all(),
            $databaseManager->listTenantDatabaseNames(),
        );
        $report = [
            'generated_at' => now()->toIso8601String(),
            'database_prefix' => $prefix,
            'matched' => array_map(
                static fn (string $database): array => ['database' => $database, 'tenant_id' => $tenantMap[$database]],
                $classification['matched'],
            ),
            'missing' => array_map(
                static fn (string $database): array => ['database' => $database, 'tenant_id' => $tenantMap[$database]],
                $classification['missing'],
            ),
            'orphaned' => array_map(
                static fn (string $database): array => ['database' => $database, 'tenant_id' => null],
                $classification['orphaned'],
            ),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $rows = [];
            foreach (['matched', 'missing', 'orphaned'] as $status) {
                foreach ($report[$status] as $entry) {
                    $rows[] = [$status, $entry['tenant_id'] ?? '-', $entry['database']];
                }
            }
            $this->table(['Status', 'Tenant ID', 'Database'], $rows);
            $this->info(sprintf(
                'Reconciliation: %d matched, %d missing, %d orphaned.',
                count($report['matched']),
                count($report['missing']),
                count($report['orphaned']),
            ));
        }

        if ($report['missing'] !== [] || $report['orphaned'] !== []) {
            if (! $this->option('json')) {
                $this->warn('No databases were modified. Back up and approve each orphan explicitly before deletion.');
            }

            return $this->option('allow-drift') ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
