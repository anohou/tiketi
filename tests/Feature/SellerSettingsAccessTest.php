<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\TicketSetting;
use App\Models\Trip;
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

class SellerSettingsAccessTest extends TestCase
{
    use InteractsWithTenantTicketing, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantTicketingTablesExist();

        Http::fake();
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'active' => true,
        ]);
    }

    private function makeStations(int $count = 2): array
    {
        $stations = [];

        for ($i = 1; $i <= $count; $i++) {
            $stations[] = Station::create([
                'name' => "Gare {$i}",
                'code' => "S{$i}",
                'city' => "Ville {$i}",
                'address' => "Adresse {$i}",
                'phone' => "123{$i}",
                'active' => true,
            ]);
        }

        return $stations;
    }

    private function makeVehicle(string $identifier): Vehicle
    {
        $vehicleType = VehicleType::firstOrCreate(['name' => 'Mini', 'seat_count' => 4, 'seat_configuration' => '2+2', 'active' => true]);

        return Vehicle::create([
            'identifier' => $identifier,
            'maker' => 'Toyota',
            'vehicle_type_id' => $vehicleType->id,
            'seat_count' => 4,
            'active' => true,
        ]);
    }

    public function test_seller_can_access_all_settings_pages(): void
    {
        $seller = $this->makeUser('seller');
        $station = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $station->id, 'active' => true]);

        $routes = [
            'seller.settings.index',
            'seller.settings.company',
            'seller.settings.loyalty',
            'seller.settings.stations',
            'seller.settings.routes',
            'seller.settings.vehicles',
            'seller.settings.team',
            'seller.settings.assignments',
            'seller.settings.trips',
            'seller.settings.profile',
        ];

        foreach ($routes as $route) {
            $this->actingAs($seller)
                ->get(route($route))
                ->assertOk();
        }
    }

    public function test_seller_settings_forbid_other_roles(): void
    {
        foreach (['admin', 'supervisor', 'accountant', 'executive', 'fleet_manager'] as $role) {
            $user = $this->makeUser($role);

            $this->actingAs($user)
                ->get(route('seller.settings.index'))
                ->assertForbidden();
        }
    }

    public function test_seller_settings_require_authentication(): void
    {
        $this->get('/seller/settings')
            ->assertRedirect(route('login'));
    }

    public function test_seller_menu_counts_match_the_scoped_landing_page_counts(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB] = $this->makeStations();
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $colleague = $this->makeUser('seller');
        UserStationAssignment::create(['user_id' => $colleague->id, 'station_id' => $stationA->id, 'active' => true]);

        $distantSeller = $this->makeUser('seller');
        UserStationAssignment::create(['user_id' => $distantSeller->id, 'station_id' => $stationB->id, 'active' => true]);

        $accessibleRoute = Route::create(['name' => 'Trajet accessible', 'origin_station_id' => $stationA->id, 'destination_station_id' => $stationB->id, 'active' => true]);
        Route::create(['name' => 'Trajet distant', 'origin_station_id' => $stationB->id, 'destination_station_id' => $stationB->id, 'active' => true]);

        Trip::create(['code' => 'V1', 'route_id' => $accessibleRoute->id, 'origin_station_id' => $stationA->id, 'destination_station_id' => $stationB->id, 'departure_at' => now()->addHour(), 'status' => 'scheduled', 'total_seats' => 50]);

        $vehicle = $this->makeVehicle('BUS-A');
        StationVehicleAssignment::create(['station_id' => $stationA->id, 'vehicle_id' => $vehicle->id, 'active' => true, 'valid_from' => now()->toDateString()]);

        $this->actingAs($seller)
            ->get(route('seller.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.stations', 1)
                ->where('stats.routes', 1)
                ->where('stats.vehicles', 1)
                ->where('stats.team', 2)
                ->where('stats.assignments', 2)
                ->where('stats.trips', 1));
    }

    public function test_stations_page_is_limited_to_assigned_stations(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB] = $this->makeStations();

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $this->actingAs($seller)
            ->get(route('seller.settings.stations'))
            ->assertOk()
            ->assertDontSee('Gare 2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('stations.data', 1)
                ->where('stations.data.0.name', 'Gare 1'));
    }

    public function test_routes_page_only_exposes_accessible_routes(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB, $stationC] = $this->makeStations(3);

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        Route::create(['name' => 'Trajet accessible', 'origin_station_id' => $stationA->id, 'destination_station_id' => $stationB->id, 'active' => true, 'estimated_duration_minutes' => 60]);
        Route::create(['name' => 'Trajet distant', 'origin_station_id' => $stationB->id, 'destination_station_id' => $stationC->id, 'active' => true, 'estimated_duration_minutes' => 90]);

        $this->actingAs($seller)
            ->get(route('seller.settings.routes'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('routes.data', 1)
                ->where('routes.data.0.name', 'Trajet accessible'));
    }

    public function test_vehicles_page_is_limited_to_own_station_pools(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB] = $this->makeStations();

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $vehicleA = $this->makeVehicle('BUS-A');
        $vehicleB = $this->makeVehicle('BUS-B');

        StationVehicleAssignment::create(['station_id' => $stationA->id, 'vehicle_id' => $vehicleA->id, 'active' => true, 'valid_from' => now()->toDateString()]);
        StationVehicleAssignment::create(['station_id' => $stationB->id, 'vehicle_id' => $vehicleB->id, 'active' => true, 'valid_from' => now()->toDateString()]);

        $this->actingAs($seller)
            ->get(route('seller.settings.vehicles'))
            ->assertOk()
            ->assertDontSee('BUS-B')
            ->assertInertia(fn (Assert $page) => $page
                ->has('vehicles', 1)
                ->where('vehicles.0.identifier', 'BUS-A'));
    }

    public function test_team_page_excludes_users_from_other_stations(): void
    {
        $seller = $this->makeUser('seller');
        $seller->update(['name' => 'Zoé Vendeuse']);
        [$stationA, $stationB] = $this->makeStations();

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $colleague = User::factory()->create(['role' => 'seller', 'name' => 'Collègue A', 'active' => true]);
        $outsider = User::factory()->create(['role' => 'seller', 'name' => 'Collègue B', 'active' => true]);

        UserStationAssignment::create(['user_id' => $colleague->id, 'station_id' => $stationA->id, 'active' => true]);
        UserStationAssignment::create(['user_id' => $outsider->id, 'station_id' => $stationB->id, 'active' => true]);

        $this->actingAs($seller)
            ->get(route('seller.settings.team'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 2)
                ->where('users.data.0.name', 'Collègue A')
                ->where('users.data.1.name', 'Zoé Vendeuse'));
    }

    public function test_assignments_page_is_scoped_to_own_stations(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB] = $this->makeStations();

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $otherSeller = User::factory()->create(['role' => 'seller', 'name' => 'Guichet A', 'active' => true]);
        $distantSeller = User::factory()->create(['role' => 'seller', 'name' => 'Guichet B', 'active' => true]);

        UserStationAssignment::create(['user_id' => $otherSeller->id, 'station_id' => $stationA->id, 'active' => true]);
        UserStationAssignment::create(['user_id' => $distantSeller->id, 'station_id' => $stationB->id, 'active' => true]);

        $this->actingAs($seller)
            ->get(route('seller.settings.assignments'))
            ->assertOk()
            ->assertSee('Guichet A')
            ->assertDontSee('Guichet B')
            ->assertInertia(fn (Assert $page) => $page->has('assignments.data', 2));
    }

    public function test_trips_page_is_scoped_to_accessible_routes_and_stations(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB, $stationC] = $this->makeStations(3);

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $routeAccessible = Route::create(['name' => 'Trajet A', 'origin_station_id' => $stationA->id, 'destination_station_id' => $stationB->id, 'active' => true]);
        $routeDistant = Route::create(['name' => 'Trajet C', 'origin_station_id' => $stationB->id, 'destination_station_id' => $stationC->id, 'active' => true]);

        Trip::create(['code' => 'V1', 'route_id' => $routeAccessible->id, 'origin_station_id' => $stationA->id, 'destination_station_id' => $stationB->id, 'vehicle_id' => null, 'departure_at' => now()->addHour(), 'status' => 'scheduled', 'total_seats' => 50]);
        Trip::create(['code' => 'V2', 'route_id' => $routeDistant->id, 'origin_station_id' => $stationB->id, 'destination_station_id' => $stationC->id, 'vehicle_id' => null, 'departure_at' => now()->addHours(2), 'status' => 'scheduled', 'total_seats' => 50]);

        $this->actingAs($seller)
            ->get(route('seller.settings.trips'))
            ->assertOk()
            ->assertDontSee('V2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('trips.data.0.code', 'V1'));
    }

    public function test_profile_page_reveals_supervisors_of_own_stations(): void
    {
        $seller = $this->makeUser('seller');
        [$stationA, $stationB] = $this->makeStations();

        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $stationA->id, 'active' => true]);

        $supervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Superviseur A', 'active' => true]);
        UserStationAssignment::create(['user_id' => $supervisor->id, 'station_id' => $stationA->id, 'active' => true]);

        $this->actingAs($seller)
            ->get(route('seller.settings.profile'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('profile.role', 'seller')
                ->where('profile.supervisors.0.name', 'Superviseur A')
                ->has('directives'));
    }

    public function test_seller_cannot_reach_admin_write_pages(): void
    {
        $seller = $this->makeUser('seller');

        $this->actingAs($seller)
            ->get(route('admin.vehicles.index'))
            ->assertForbidden();

        $this->actingAs($seller)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_seller_cannot_access_okohi_transaction_history(): void
    {
        $seller = $this->makeUser('seller');

        $this->actingAs($seller)
            ->get('/seller/settings/fidelisation/transactions')
            ->assertNotFound();
    }

    public function test_seller_loyalty_and_company_props_expose_no_sensitive_data(): void
    {
        Http::fake(fn () => throw new ConnectionException('fake connection error'));

        TicketSetting::query()->delete();
        TicketSetting::create([
            'company_name' => 'TEST TRANSPORT',
            'okohi_integration_url' => 'http://127.0.0.1:8001',
            'okohi_integration_key' => 'super_secret_integration_key_123',
        ]);

        $seller = $this->makeUser('seller');

        $this->actingAs($seller)
            ->get(route('seller.settings.loyalty'))
            ->assertOk()
            ->assertDontSee('super_secret_integration_key_123')
            ->assertInertia(fn (Assert $page) => $page
                ->where('loyalty.connected', true)
                ->has('loyalty.rewards')
                ->missing('loyalty.okohi_integration_url')
                ->missing('loyalty.okohi_base_url')
                ->missing('loyalty.okohi_integration_key')
                ->missing('settings'));

        $this->actingAs($seller)
            ->get(route('seller.settings.company'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('operationalSettings')
                ->where('company.name', 'TEST TRANSPORT')
                ->missing('company.id')
                ->missing('company.data')
                ->missing('company.db_name'));
    }

    public function test_vehicles_page_excludes_expired_and_future_pools(): void
    {
        $seller = $this->makeUser('seller');
        $station = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $station->id, 'active' => true]);

        $vehicleValid = $this->makeVehicle('BUS-VALID');
        $vehicleExpired = $this->makeVehicle('BUS-EXPIRED');
        $vehicleFuture = $this->makeVehicle('BUS-FUTURE');

        StationVehicleAssignment::create([
            'station_id' => $station->id,
            'vehicle_id' => $vehicleValid->id,
            'active' => true,
            'valid_from' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ]);
        StationVehicleAssignment::create([
            'station_id' => $station->id,
            'vehicle_id' => $vehicleExpired->id,
            'active' => true,
            'valid_from' => now()->subDays(30)->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        StationVehicleAssignment::create([
            'station_id' => $station->id,
            'vehicle_id' => $vehicleFuture->id,
            'active' => true,
            'valid_from' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.settings.vehicles'))
            ->assertOk()
            ->assertDontSee('BUS-EXPIRED')
            ->assertDontSee('BUS-FUTURE')
            ->assertSee('BUS-VALID')
            ->assertInertia(fn (Assert $page) => $page
                ->where('assignments.total', 1)
                ->has('vehicles', 1)
                ->where('vehicles.0.identifier', 'BUS-VALID'));
    }

    public function test_seller_cannot_run_admin_loyalty_and_enterprise_mutations(): void
    {
        $seller = $this->makeUser('seller');

        $this->actingAs($seller)
            ->post(route('admin.settings.loyalty.connect'), ['code' => '1234'])
            ->assertForbidden();

        $this->actingAs($seller)
            ->delete(route('admin.settings.loyalty.disconnect'))
            ->assertForbidden();

        $this->actingAs($seller)
            ->post(route('admin.settings.loyalty.rewards.store'), [])
            ->assertForbidden();

        $this->actingAs($seller)
            ->post(route('admin.settings.enterprise.update'), [])
            ->assertForbidden();
    }

    public function test_seller_settings_pages_receive_role_adapted_titles(): void
    {
        $seller = $this->makeUser('seller');
        $station = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $station->id, 'active' => true]);

        $this->actingAs($seller)
            ->get(route('seller.settings.team'))
            ->assertInertia(fn (Assert $page) => $page->where('title', 'Équipe de ma gare'));

        $this->actingAs($seller)
            ->get(route('seller.settings.vehicles'))
            ->assertInertia(fn (Assert $page) => $page->where('title', 'Véhicules de ma gare'));

        $this->actingAs($seller)
            ->get(route('seller.settings.trips'))
            ->assertInertia(fn (Assert $page) => $page->where('title', 'Voyages de mes trajets'));
    }
}
