<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

/**
 * Tenant Model
 * 
 * Represents a transport company with its own isolated database.
 * Each tenant can have multiple domains (custom domain + subdomain).
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    /**
     * Custom columns stored in the tenants table (not in JSON 'data' column)
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'email',
            'phone',
        ];
    }

    /**
     * Get the tenant's primary domain
     */
    public function getPrimaryDomainAttribute(): ?string
    {
        return $this->domains()->first()?->domain;
    }
}
