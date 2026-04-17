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
        $provisionerUser = config('database.connections.tenant_provisioner.username');
        $schema = config('database.connections.pgsql.search_path', 'public');

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
        $this->tenantProvisionerConnection($name)->statement(sprintf(
            'CREATE SCHEMA IF NOT EXISTS %s AUTHORIZATION %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($provisionerUser),
        ));
        $this->tenantProvisionerConnection($name)->statement(sprintf(
            'GRANT USAGE, CREATE ON SCHEMA %s TO %s',
            $this->quoteIdentifier($schema),
            $this->quoteIdentifier($appUser),
        ));

        Log::info('Tenant PostgreSQL database created', [
            'tenant_id' => $tenant->id,
            'database_name' => $name,
        ]);

        return true;
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();
        $this->validateDatabaseName($name);

        $connection = $this->getProvisionerConnection();
        $connection->statement(sprintf(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = %s AND pid <> pg_backend_pid()",
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

        $expectedPrefix = config('tenancy.database.prefix', 'app_tenant_tiketi_');
        if (! str_starts_with($name, $expectedPrefix)) {
            throw new \InvalidArgumentException(
                "Database name must start with prefix: {$expectedPrefix}",
            );
        }
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function tenantProvisionerConnection(string $database): ConnectionInterface
    {
        $config = config('database.connections.tenant_provisioner');
        $config['database'] = $database;

        config(['database.connections.tenant_provisioner_database' => $config]);
        DB::purge('tenant_provisioner_database');

        return DB::connection('tenant_provisioner_database');
    }
}
