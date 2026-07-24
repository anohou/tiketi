<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_is_redirected_away_from_tenant_admin_routes_without_tenancy(): void
    {
        config(['tenancy.enforce_route_boundary_in_testing' => true]);
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)->get('/admin');

        $response->assertRedirectToRoute('landlord.tenants.index');
    }

    public function test_legacy_superadmin_remains_valid_during_zero_downtime_rollout(): void
    {
        config(['tenancy.enforce_route_boundary_in_testing' => true]);
        $legacySuperAdmin = User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($legacySuperAdmin)->get('/admin');

        $response->assertRedirectToRoute('landlord.tenants.index');
    }

    public function test_central_admin_command_rejects_tenant_admin_role(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Central Admin',
            '--email' => 'central@example.com',
            '--password' => 'ValidPassword!123',
            '--role' => 'admin',
            '--force' => true,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'central@example.com']);
    }

    public function test_central_admin_command_creates_native_super_admin_by_default(): void
    {
        $this->artisan('admin:create', [
            '--name' => 'Platform Admin',
            '--email' => 'platform@example.com',
            '--password' => 'ValidPassword!123',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'platform@example.com',
            'role' => 'super_admin',
        ]);
    }

    public function test_existing_admin_error_explains_the_make_update_interface(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->artisan('admin:create', [
            '--name' => 'Existing Admin',
            '--email' => 'existing@example.com',
            '--password' => 'ValidPassword!123',
            '--force' => true,
        ])
            ->expectsOutputToContain('ADMIN_UPDATE=true make create-app-admin')
            ->assertFailed();
    }
}
