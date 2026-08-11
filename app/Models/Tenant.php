<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant Model
 *
 * Represents a transport company with its own isolated database.
 * Each tenant can have multiple domains (custom domain + subdomain).
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $guarded = [];

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

    /**
     * Feature flag par tenant (stocké dans la colonne JSON `data`).
     *
     * Flags actuels :
     * - departure_programs : active les programmes de départ (masque la case
     *   « voyage récurrent » et désactive trips:replicate) ;
     * - round_trip_sales : active les ventes aller-retour.
     */
    public function featureFlag(string $key, bool $default = false): bool
    {
        return (bool) ($this->featureFlags()[$key] ?? $default);
    }

    /**
     * Tous les feature flags normalisés du tenant.
     *
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        // stancl stocke les attributs non-custom comme attributs custom
        // (feature_flags) ; repli sur la colonne data pour compatibilité.
        $flags = $this->feature_flags
            ?? ($this->data['feature_flags'] ?? null)
            ?? [];

        if (is_string($flags)) {
            $flags = json_decode($flags, true) ?? [];
        }

        return is_array($flags) ? $flags : [];
    }

    /**
     * Met à jour uniquement les flags fournis sans supprimer les autres.
     *
     * @param  array<string, bool>  $flags
     */
    public function mergeFeatureFlags(array $flags): void
    {
        $this->feature_flags = array_merge($this->featureFlags(), $flags);
    }

    public function departureProgramsEnabled(): bool
    {
        return $this->featureFlag('departure_programs');
    }

    public function roundTripSalesEnabled(): bool
    {
        return $this->featureFlag('round_trip_sales');
    }
}
