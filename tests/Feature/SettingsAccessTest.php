<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\TicketSetting;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class SettingsAccessTest extends TestCase
{
    use InteractsWithTenantTicketing, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantTicketingTablesExist();
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'active' => true,
        ]);
    }

    public function test_settings_page_requires_authentication(): void
    {
        $this->get('/settings')
            ->assertRedirect(route('login'));
    }

    public function test_settings_page_is_accessible_to_all_tenant_roles(): void
    {
        foreach (['admin', 'supervisor', 'accountant', 'executive', 'fleet_manager'] as $role) {
            $user = $this->makeUser($role);

            $this->actingAs($user)
                ->get(route('settings.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Settings/Index')
                    ->where('role', $role)
                    ->when(
                        in_array($role, ['admin', 'supervisor'], true),
                        fn (Assert $page) => $page->has('stats'),
                        fn (Assert $page) => $page->has('profile')->has('company')->has('scope')->has('directives'),
                    ));
        }

        $seller = $this->makeUser('seller');

        $this->actingAs($seller)
            ->get(route('settings.index'))
            ->assertRedirect(route('seller.settings.index'));
    }

    public function test_settings_page_forbids_unknown_roles(): void
    {
        $user = $this->makeUser('unknown');

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_admin_receives_original_configuration_grid_without_consultation_props(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats')
                ->missing('scope')
                ->missing('directives')
                ->missing('configAlerts'));
    }

    public function test_supervisor_receives_configuration_grid_with_own_stats(): void
    {
        $supervisor = $this->makeUser('supervisor');

        $stationA = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        $stationB = Station::create(['name' => 'Gare B', 'code' => 'B', 'city' => 'B', 'active' => true]);

        UserStationAssignment::create(['user_id' => $supervisor->id, 'station_id' => $stationA->id, 'active' => true]);

        $sellerA = User::factory()->create(['role' => 'seller', 'active' => true]);
        $sellerB = User::factory()->create(['role' => 'seller', 'active' => true]);

        UserStationAssignment::create(['user_id' => $sellerA->id, 'station_id' => $stationA->id, 'active' => true]);
        UserStationAssignment::create(['user_id' => $sellerB->id, 'station_id' => $stationB->id, 'active' => true]);

        $this->actingAs($supervisor)
            ->get(route('settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('stats')
                ->where('stats.stations', 1)
                ->where('stats.users', 1)
                ->missing('scope'));
    }

    public function test_seller_is_redirected_to_the_seller_settings_landing(): void
    {
        $seller = $this->makeUser('seller');

        $stationA = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        Station::create(['name' => 'Gare B', 'code' => 'B', 'city' => 'B', 'active' => true]);

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $this->actingAs($seller)
            ->get(route('settings.index'))
            ->assertRedirect(route('seller.settings.index'))
            ->assertDontSee('Gare B');
    }

    public function test_fleet_manager_receives_fleet_scope(): void
    {
        $fleetManager = $this->makeUser('fleet_manager');

        $vehicleType = VehicleType::create(['name' => 'Mini', 'seat_count' => 4, 'seat_configuration' => '2+2', 'active' => true]);
        Vehicle::create(['identifier' => 'BUS-1', 'maker' => 'Toyota', 'vehicle_type_id' => $vehicleType->id, 'seat_count' => 4, 'active' => true]);

        $this->actingAs($fleetManager)
            ->get(route('settings.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope.type', 'fleet_manager')
                ->where('scope.vehicles.0.identifier', 'BUS-1')
                ->has('scope.vehicles', 1));
    }

    public function test_accountant_and_executive_receive_their_scope(): void
    {
        $accountant = $this->makeUser('accountant');

        $this->actingAs($accountant)
            ->get(route('settings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('scope.type', 'accountant'));

        $executive = $this->makeUser('executive');

        $this->actingAs($executive)
            ->get(route('settings.index'))
            ->assertInertia(fn (Assert $page) => $page->where('scope.type', 'executive'));
    }

    public function test_admin_dashboard_receives_config_alerts(): void
    {
        $admin = $this->makeUser('admin');
        $sellerWithoutStation = User::factory()->create(['role' => 'seller', 'active' => true]);
        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);

        $station = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $station->id, 'active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboards/Admin')
                ->has('configAlerts')
                ->where('configAlerts.0.id', 'sellers-without-station'));

        $this->actingAs($sellerWithoutStation)
            ->get(route('seller.settings.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('configAlerts'));
    }

    public function test_settings_never_exposes_okohi_integration_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('fake connection error'));

        TicketSetting::query()->delete();
        TicketSetting::create([
            'company_name' => 'TEST TRANSPORT',
            'okohi_integration_url' => 'http://127.0.0.1:8001',
            'okohi_integration_key' => 'super_secret_integration_key_123',
        ]);

        $seller = $this->makeUser('seller');

        $response = $this->actingAs($seller)
            ->get(route('seller.settings.loyalty'));

        $response->assertOk();
        $response->assertDontSee('super_secret_integration_key_123');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('loyalty.connected', true)
            ->has('loyalty.rewards')
            ->missing('loyalty.okohi_integration_url')
            ->missing('loyalty.okohi_base_url')
            ->missing('loyalty.okohi_integration_key')
            ->missing('settings'));
    }
}
