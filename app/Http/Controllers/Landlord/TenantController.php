<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Rules\AllowedTenantDomain;
use App\Support\TenantDomainPolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Stancl\Tenancy\Database\Models\Domain;

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
    public function store(Request $request)
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

        // Create the tenant (this triggers database creation and migrations)
        $tenant = Tenant::create([
            'id' => $validated['id'],
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
        ]);

        // Add the primary domain
        $tenant->domains()->create([
            'domain' => $validated['domain'],
        ]);

        // Generate strong password (10 chars)
        $password = \Illuminate\Support\Str::password(10, true, true, false, false);

        // Create Tenant Admin
        $tenant->run(function () use ($validated, $password) {
             \App\Models\User::create([
                'name' => 'Admin ' . $validated['name'],
                'email' => $validated['email'] ?? ('admin@' . $validated['id'] . '.com'),
                'password' => \Illuminate\Support\Facades\Hash::make($password),
                'role' => 'admin',
             ]);
        });

        return redirect()->route('landlord.tenants.index')
            ->with('success', "Tenant '{$tenant->name}' created successfully with domain '{$validated['domain']}'")
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
