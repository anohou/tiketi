<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\AllowedTenantDomain;
use App\Support\TenantDomainPolicy;
use App\TenantDatabaseManagers\SecurePostgreSQLDatabaseManager;
use Database\Seeders\DestinationSeeder;
use Database\Seeders\ProductionVehicleTypeSeeder;
use Database\Seeders\VehicleTypeSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Stancl\Tenancy\Database\Models\Domain;
use Throwable;

/**
 * TenantController - Manages tenants (transport companies) from central admin
 */
class TenantController extends Controller
{
    /**
     * Display a listing of all tenants
     */
    public function index()
    {
        $tenants = Tenant::with('domains')->get();

        return Inertia::render('Landlord/Tenants/Index', [
            'tenants' => $tenants,
            'tenantDomainPolicy' => TenantDomainPolicy::toFrontendArray(),
        ]);
    }

    /**
     * Show the form for creating a new tenant
     */
    public function create()
    {
        return Inertia::render('Landlord/Tenants/Create');
    }

    /**
     * Store a newly created tenant
     */
    public function store(Request $request, SecurePostgreSQLDatabaseManager $databaseManager)
    {
        $request->merge([
            'domain' => AllowedTenantDomain::normalize($request->input('domain')),
        ]);

        $validated = $request->validate([
            'id' => 'required|string|max:50|unique:tenants,id|alpha_dash',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'domain' => ['required', 'string', 'max:255', 'unique:domains,domain', new AllowedTenantDomain],
        ]);

        $databaseName = $this->tenantDatabaseName($validated['id']);
        if ($databaseManager->databaseExists($databaseName)) {
            throw ValidationException::withMessages([
                'id' => "The tenant database [{$databaseName}] already exists. Run tenants:reconcile-databases and resolve the orphaned database before retrying.",
            ]);
        }

        try {
            // Creating the model synchronously creates and migrates its database.
            $tenant = Tenant::create([
                'id' => $validated['id'],
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ]);
        } catch (Throwable $exception) {
            $this->cleanUpFailedProvisioning(
                $validated['id'],
                $databaseName,
                $databaseManager,
                $exception,
            );

            throw $exception;
        }

        // Add the primary domain
        $tenant->domains()->create([
            'domain' => $validated['domain'],
        ]);

        // Generate strong password (10 chars)
        $password = Str::password(10, true, true, false, false);

        // Initialize Tenant Data and Create Admin
        $tenant->run(function () use ($validated, $password) {
            // Production tenants only get the two real 50-seat vehicle types.
            if (app()->isProduction()) {
                Artisan::call('db:seed', [
                    '--class' => ProductionVehicleTypeSeeder::class,
                    '--force' => true,
                ]);
            } else {
                // Non-production tenants keep the demo data for convenience.
                Artisan::call('db:seed', [
                    '--class' => DestinationSeeder::class,
                    '--force' => true,
                ]);
                Artisan::call('db:seed', [
                    '--class' => VehicleTypeSeeder::class,
                    '--force' => true,
                ]);
            }

            User::create([
                'name' => 'Admin '.$validated['name'],
                'email' => $validated['email'] ?? ('admin@'.$validated['id'].'.com'),
                'password' => Hash::make($password),
                'role' => 'admin',
            ]);
        });

        return redirect()->route('landlord.tenants.index')
            ->with('success', "Tenant '{$tenant->name}' created successfully with domain '{$validated['domain']}'")
            ->with('tenant_admin_password', $password);
    }

    private function tenantDatabaseName(string $tenantId): string
    {
        return (string) config('tenancy.database.prefix')
            .$tenantId
            .(string) config('tenancy.database.suffix', '');
    }

    private function cleanUpFailedProvisioning(
        string $tenantId,
        string $databaseName,
        SecurePostgreSQLDatabaseManager $databaseManager,
        Throwable $provisioningException,
    ): void {
        try {
            // The preflight proved this database did not exist before this request,
            // so it is safe to remove if the synchronous provisioning pipeline
            // created it and then failed during migration.
            if ($databaseManager->databaseExists($databaseName)) {
                $failedTenant = new Tenant(['id' => $tenantId]);
                $databaseManager->deleteDatabase($failedTenant);
            }

            // Builder deletes intentionally bypass TenantDeleted, which would
            // otherwise attempt a second database deletion.
            Tenant::query()->whereKey($tenantId)->delete();
        } catch (Throwable $cleanupException) {
            Log::critical('Failed to clean up tenant after provisioning error.', [
                'tenant_id' => $tenantId,
                'database_name' => $databaseName,
                'provisioning_error' => $provisioningException->getMessage(),
                'cleanup_error' => $cleanupException->getMessage(),
            ]);
        }
    }

    /**
     * Regenerate the tenant administrator password
     */
    public function regenerateAdminPassword(Tenant $tenant)
    {
        $password = Str::password(10, true, true, false, false);

        $tenant->run(function () use ($password) {
            $admin = User::query()
                ->where('role', 'admin')
                ->orderBy('created_at')
                ->first();

            if (! $admin) {
                abort(404, 'Tenant admin user not found.');
            }

            $admin->update([
                'password' => Hash::make($password),
            ]);
        });

        return redirect()->route('landlord.tenants.index')
            ->with('success', "Tenant administrator password regenerated successfully for '{$tenant->name}'.")
            ->with('tenant_admin_password', $password);
    }

    /**
     * Display the specified tenant
     */
    public function show(Tenant $tenant)
    {
        $tenant->load('domains');

        return Inertia::render('Landlord/Tenants/Show', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Show the form for editing the specified tenant
     */
    public function edit(Tenant $tenant)
    {
        $tenant->load('domains');

        return Inertia::render('Landlord/Tenants/Edit', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Update the specified tenant
     */
    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $tenant->update($validated);

        return redirect()->route('landlord.tenants.index')
            ->with('success', "Tenant '{$tenant->name}' updated successfully");
    }

    /**
     * Remove the specified tenant (and its database!)
     */
    public function destroy(Tenant $tenant)
    {
        $name = $tenant->name;

        // This will also delete the tenant's database
        $tenant->delete();

        return redirect()->route('landlord.tenants.index')
            ->with('success', "Tenant '{$name}' and its database have been deleted");
    }

    /**
     * Add a domain to a tenant
     */
    public function addDomain(Request $request, Tenant $tenant)
    {
        $request->merge([
            'domain' => AllowedTenantDomain::normalize($request->input('domain')),
        ]);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255', 'unique:domains,domain', new AllowedTenantDomain],
        ]);

        $tenant->domains()->create([
            'domain' => $validated['domain'],
        ]);

        return redirect()->back()
            ->with('success', "Domain '{$validated['domain']}' added to tenant '{$tenant->name}'");
    }

    /**
     * Remove a domain from a tenant
     */
    public function removeDomain(Tenant $tenant, Domain $domain)
    {
        // Ensure the domain belongs to this tenant
        if ($domain->tenant_id !== $tenant->id) {
            abort(403, 'Domain does not belong to this tenant');
        }

        // Don't allow removing the last domain
        if ($tenant->domains()->count() <= 1) {
            return redirect()->back()
                ->with('error', 'Cannot remove the last domain. Tenant must have at least one domain.');
        }

        $domainName = $domain->domain;
        $domain->delete();

        return redirect()->back()
            ->with('success', "Domain '{$domainName}' removed from tenant '{$tenant->name}'");
    }
}
