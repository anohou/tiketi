<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_is_redirected_away_from_tenant_admin_routes_without_tenancy(): void
    {
        config(['tenancy.enforce_route_boundary_in_testing' => true]);
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($superadmin)->get('/admin');

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
}
