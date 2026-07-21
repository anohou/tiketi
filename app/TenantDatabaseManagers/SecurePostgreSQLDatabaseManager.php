<?php

declare(strict_types=1);

namespace App\TenantDatabaseManagers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

/**
 * Secure PostgreSQL database manager for tenant provisioning.
 *
 * A dedicated provisioner role creates and drops tenant databases. The runtime
 * app role receives scoped privileges inside each tenant database so tenant
 * traffic and migrations do not need elevated provisioner credentials.
 */
class SecurePostgreSQLDatabaseManager extends PostgreSQLDatabaseManager
{
    protected function getProvisionerConnection(): ConnectionInterface
    {
        return DB::connection('tenant_provisioner');
    }

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();
        $this->validateDatabaseName($name);

        $appUser = config('database.connections.pgsql.username');
        $backupUser = config('database.connections.tenant_backup.username');
        $provisionerUser = config('database.connections.tenant_provisioner.username');
        $schema = $this->primarySchemaName(config('database.connections.pgsql.search_path', 'public'));

        if (! is_string($appUser) || $appUser === '') {
            throw new \RuntimeException('PostgreSQL app username is not configured.');
        }
        if (! is_string($provisionerUser) || $provisionerUser === '') {
            throw new \RuntimeException('PostgreSQL tenant provisioner username is not configured.');
        }
        if (! is_string($schema) || $schema === '') {
            throw new \RuntimeException('PostgreSQL tenant schema is not configured.');
        }

        Log::info('Starting tenant PostgreSQL database creation', [
            'tenant_id' => $tenant->id,
            'database_name' => $name,
            'owner' => $provisionerUser,
            'app_user' => $appUser,
            'schema' => $schema,
        ]);

        $this->getProvisionerConnection()->statement(sprintf(
            'CREATE DATABASE %s WITH OWNER %s TEMPLATE template0',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($provisionerUser),
        ));

        $this->getProvisionerConnection()->statement(sprintf(
            'GRANT CONNECT, TEMPORARY ON DATABASE %s TO %s',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($appUser),
        ));
        if (is_string($backupUser) && $backupUser !== '') {
            $this->getProvisionerConnection()->statement(sprintf(
                'GRANT CONNECT ON DATABASE %s TO %s',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($backupUser),
            ));
        }
        $tenantProvisioner = $this->tenantProvisionerConnection($name);

        $tenantProvisioner->statement(sprintf(
            'CREATE SCHEMA IF NOT EXISTS %s AUTHORIZATION %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($provisionerUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'GRANT USAGE, CREATE ON SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT ALL PRIVILEGES ON TABLES TO %s',
            $this->quoteIdentifier($provisionerUser),
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT ALL PRIVILEGES ON SEQUENCES TO %s',
            $this->quoteIdentifier($provisionerUser),
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));
        $tenantProvisioner->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT EXECUTE ON FUNCTIONS TO %s',
            $this->quoteIdentifier($provisionerUser),
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));

        Log::info('Tenant PostgreSQL database created', [
            'tenant_id' => $tenant->id,
            'database_name' => $name,
            'search_path_schema' => $schema,
            'search_path_strategy' => 'connection-or-role-default',
        ]);

        return true;
    }

    public function grantBackupPrivileges(TenantWithDatabase $tenant): void
    {
        $name = $tenant->database()->getName();
        $this->validateDatabaseName($name);

        $backupUser = config('database.connections.tenant_backup.username');
        if (! is_string($backupUser) || $backupUser === '') {
            Log::warning('Skipping tenant backup grants because DB_BACKUP_USER is not configured.', [
                'tenant_id' => $tenant->id,
                'database_name' => $name,
            ]);

            return;
        }

        $appUser = config('database.connections.pgsql.username');
        $schema = $this->primarySchemaName(config('database.connections.pgsql.search_path', 'public'));
        if (! is_string($appUser) || $appUser === '') {
            throw new \RuntimeException('PostgreSQL app username is not configured.');
        }

        // Database and schema ownership belong to the provisioner. PostgreSQL
        // only emits a warning when a non-owner attempts some GRANT statements,
        // so executing every grant through the runtime connection can appear to
        // succeed while leaving the backup role without schema access.
        $this->getProvisionerConnection()->statement(sprintf(
            'GRANT CONNECT ON DATABASE %s TO %s',
            $this->quoteIdentifier($name),
            $this->quoteIdentifier($backupUser),
        ));
        $provisionerConnection = $this->tenantProvisionerConnection($name);
        $provisionerConnection->statement(sprintf(
            'GRANT USAGE ON SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($backupUser),
        ));

        // Tenant migrations run as the application role, which owns their
        // tables and sequences. Object and default privileges must therefore be
        // granted through that role rather than through the schema owner.
        $connection = $this->tenantRuntimeConnection($name);
        $connection->statement(sprintf(
            'GRANT SELECT ON ALL TABLES IN SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($backupUser),
        ));
        $connection->statement(sprintf(
            'GRANT SELECT ON ALL SEQUENCES IN SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($backupUser),
        ));
        $connection->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT SELECT ON TABLES TO %s',
            $this->quoteIdentifier($appUser),
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($backupUser),
        ));
        $connection->statement(sprintf(
            'ALTER DEFAULT PRIVILEGES FOR ROLE %s IN SCHEMA %s GRANT SELECT ON SEQUENCES TO %s',
            $this->quoteIdentifier($appUser),
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($backupUser),
        ));

        $schemaGranted = $provisionerConnection->selectOne(
            'SELECT CASE WHEN has_schema_privilege(?, ?, \'USAGE\') THEN 1 ELSE 0 END AS granted',
            [$backupUser, $schema],
        );
        $missingTables = $provisionerConnection->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS count
                FROM pg_class AS c
                JOIN pg_namespace AS n ON n.oid = c.relnamespace
                WHERE n.nspname = ?
                  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                  AND NOT has_table_privilege(?, format('%I.%I', n.nspname, c.relname), 'SELECT')
                SQL,
            [$schema, $backupUser],
        );
        $missingSequences = $provisionerConnection->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS count
                FROM pg_class AS c
                JOIN pg_namespace AS n ON n.oid = c.relnamespace
                WHERE n.nspname = ?
                  AND c.relkind = 'S'
                  AND NOT has_sequence_privilege(?, format('%I.%I', n.nspname, c.relname), 'SELECT')
                SQL,
            [$schema, $backupUser],
        );

        if ((int) ($schemaGranted?->granted ?? 0) !== 1
            || (int) ($missingTables?->count ?? -1) !== 0
            || (int) ($missingSequences?->count ?? -1) !== 0) {
            throw new \RuntimeException(
                "Backup privilege verification failed for tenant database [{$name}]."
            );
        }
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();
        $this->validateDatabaseName($name);

        $connection = $this->getProvisionerConnection();
        $connection->statement(sprintf(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()',
            $connection->getPdo()->quote($name),
        ));
        $connection->statement(sprintf('DROP DATABASE IF EXISTS %s', $this->quoteIdentifier($name)));

        return true;
    }

    public function databaseExists(string $name): bool
    {
        $this->validateDatabaseName($name);

        return (bool) $this->getProvisionerConnection()->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$name],
        );
    }

    /** @return list<string> */
    public function listTenantDatabaseNames(): array
    {
        $prefix = config('tenancy.database.prefix');
        if (! is_string($prefix) || $prefix === '') {
            throw new \RuntimeException('TENANT_DB_PREFIX must be configured before reconciling tenant databases.');
        }

        return array_values(array_map(
            static fn (object $row): string => (string) $row->datname,
            $this->getProvisionerConnection()->select(
                'SELECT datname FROM pg_database WHERE datname LIKE ? ORDER BY datname',
                [$prefix.'%'],
            ),
        ));
    }

    protected function validateDatabaseName(string $name): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid database name: {$name}. Only alphanumeric, underscore, and hyphen characters are allowed.",
            );
        }

        if (strlen($name) > 63) {
            throw new \InvalidArgumentException(
                "Database name too long: {$name}. PostgreSQL identifiers are limited to 63 bytes.",
            );
        }

        $expectedPrefix = config('tenancy.database.prefix');
        if (empty($expectedPrefix)) {
            // Log a warning but don't fail immediately if prefix is intentionally empty
            \Log::warning('Tenant database prefix is not configured. Security validation might be bypassed.');

            return;
        }

        if (! str_starts_with($name, $expectedPrefix)) {
            throw new \InvalidArgumentException(
                "Database name [{$name}] must start with the configured prefix: [{$expectedPrefix}]. ".
                'Please check TENANT_DB_PREFIX in your .env file.'
            );
        }
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    protected function primarySchemaName(mixed $searchPath): string
    {
        if (! is_string($searchPath) || trim($searchPath) === '') {
            return 'public';
        }

        $primarySchema = trim(explode(',', $searchPath)[0]);
        $primarySchema = trim($primarySchema, " \t\n\r\0\x0B\"");

        return $primarySchema !== '' ? $primarySchema : 'public';
    }

    protected function tenantProvisionerConnection(string $database): ConnectionInterface
    {
        $config = config('database.connections.tenant_provisioner');
        $config['database'] = $database;

        config(['database.connections.tenant_provisioner_database' => $config]);
        DB::purge('tenant_provisioner_database');

        return DB::connection('tenant_provisioner_database');
    }

    protected function tenantRuntimeConnection(string $database): ConnectionInterface
    {
        $config = config('database.connections.pgsql');
        $config['database'] = $database;

        config(['database.connections.tenant_runtime_database' => $config]);
        DB::purge('tenant_runtime_database');

        return DB::connection('tenant_runtime_database');
    }
}
