<?php

declare(strict_types=1);

namespace App\TenantDatabaseManagers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;

/**
 * Secure MySQL Database Manager for Tenant Provisioning
 *
 * Uses dedicated provisioner user with minimal privileges:
 * - App user (svc_app_rw_stg_2025): read/write tenant data
 * - Provisioner user (svc_tenant_provisioner_stg_2025): create databases + grant permissions
 *
 * Security benefits:
 * - App runs with minimal privileges
 * - Database creation isolated to specific operation
 * - Clear audit trail
 */
class SecureMySQLDatabaseManager extends MySQLDatabaseManager
{
    /**
     * Get the provisioner database connection
     */
    protected function getProvisionerConnection(): ConnectionInterface
    {
        return DB::connection('tenant_provisioner');
    }

    /**
     * Create tenant database using provisioner credentials
     */
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();

        // Validate database name
        $this->validateDatabaseName($name);

        // Use provisioner connection for database creation
        $provisioner = $this->getProvisionerConnection();

        // Create database with UTF8MB4
        $charset = config('database.connections.mysql.charset', 'utf8mb4');
        $collation = config('database.connections.mysql.collation', 'utf8mb4_unicode_ci');

        $provisioner->statement(
            "CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$collation}"
        );

        // Grant app user access to this specific tenant database
        $appUser = config('database.connections.mysql.username');
        $appHost = $this->getAppUserHost();

        // Grant all privileges on the new tenant database to app user
        $provisioner->statement(
            "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$appUser}'@'{$appHost}'"
        );

        $provisioner->statement("FLUSH PRIVILEGES");

        return true;
    }

    /**
     * Delete tenant database
     * Note: Uses provisioner connection which has DROP privilege on tenant databases
     */
    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $name = $tenant->database()->getName();

        $provisioner = $this->getProvisionerConnection();

        $provisioner->statement("DROP DATABASE IF EXISTS `{$name}`");

        return true;
    }

    /**
     * Validate database name to prevent SQL injection
     */
    protected function validateDatabaseName(string $name): void
    {
        // Check for valid characters (alphanumeric + underscore)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid database name: {$name}. Only alphanumeric and underscore allowed."
            );
        }

        // Check length (MySQL limit is 64 characters)
        if (strlen($name) > 64) {
            throw new \InvalidArgumentException(
                "Database name too long: {$name}. Maximum 64 characters allowed."
            );
        }

        // Ensure it matches expected prefix
        $expectedPrefix = config('tenancy.database.prefix', 'app_tenant_bil_');
        if (!str_starts_with($name, $expectedPrefix)) {
            throw new \InvalidArgumentException(
                "Database name must start with prefix: {$expectedPrefix}"
            );
        }
    }

    /**
     * Get the host pattern for app user grants
     * Tries to match existing grants or falls back to '%'
     */
    protected function getAppUserHost(): string
    {
        // Check environment or fallback to wildcard
        // In production, this might be a specific IP range like '10.0.0.0/8'
        return env('DB_APP_USER_HOST', '%');
    }

    /**
     * Make the database manager use the tenant database
     */
    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $databaseName;

        return $baseConfig;
    }
}
