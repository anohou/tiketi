<?php

namespace Tests\Feature;

use App\Models\DepartureSchedule;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\MaterializeScheduledTrips;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

/**
 * Point 8 : les feature flags sont imposés CÔTÉ SERVEUR (pas un simple
 * masquage d'interface) et isolés par tenant.
 */
class FeatureFlagEnforcementTest extends TestCase
{
    use InteractsWithTenantTicketing;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantTicketingTablesExist();

        OperationalSetting::current()->update([
            'settings' => [
                'operational_day_start_hour' => 3,
                'scheduled_trip_lookahead_hours' => 1,
                'default_vehicle_assignment_policy' => 'require_real_vehicle',
            ],
        ]);
    }

    private function makeStation(string $name, string $code): Station
    {
        return Station::create(['name' => $name, 'code' => $code, 'city' => $name, 'active' => true]);
    }

    private function makeTrip(Station $a, Station $b, string $identifier): Trip
    {
        static $counter = 0;
        $counter++;
        $route = Route::create(['origin_station_id' => $a->id, 'destination_station_id' => $b->id, 'name' => "{$a->name}→{$b->name}", 'active' => true]);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Type '.$counter, 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => $identifier, 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

        return Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay()->setTime(8, 0),
            'status' => 'boarding',
            'operational_ready' => true,
            'sales_ready' => true,
        ]);
    }

    private function httpRoundTripSale(Trip $trip, Station $a, Station $b): TestResponse
    {
        // Remise globale aller-retour : 2×3000 − 500 = 5500.
        $this->setRoundTripDiscount(500);

        $seller = User::factory()->create(['role' => 'admin', 'active' => true]);

        return $this->actingAs($seller)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seats' => [1],
            'journey_type' => 'round_trip',
            'return_mode' => 'open',
            'passenger_name' => 'Client Flag',
            'passenger_phone' => '2250700000001',
            'amount' => 5500,
        ]);
    }

    private function makeTenant(string $id, string $name, array $flags): Tenant
    {
        // Insertion directe : évite les hooks stancl de création de base.
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => $name,
            'data' => json_encode(['feature_flags' => $flags]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant = Tenant::find($id);

        // Point 8 : utilise le VRAI contexte tenancy (tenancy()->initialize +
        // tenant()), comme la commande réelle. On vide la liste des
        // bootstrappers pour CE test : initialize() binde le tenant dans le
        // conteneur et met initialized = true, sans créer de base SQLite dans
        // le dépôt ni basculer la connexion (point 12).
        config(['tenancy.bootstrappers' => []]);
        tenancy()->initialize($tenant);

        return $tenant;
    }

    protected function tearDown(): void
    {
        // Fin du contexte tenancy simulé (aucune base créée, aucun fichier).
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }
        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
            CacheTenancyBootstrapper::class,
            FilesystemTenancyBootstrapper::class,
            QueueTenancyBootstrapper::class,
        ]]);

        parent::tearDown();
    }

    public function test_round_trip_sale_is_rejected_when_flag_disabled(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FLAG-OFF');

        $this->makeTenant('flag-disabled-tenant', 'Tenant Sans AR', ['round_trip_sales' => false]);

        $response = $this->httpRoundTripSale($trip, $a, $b);
        $response->assertStatus(403)
            ->assertJsonPath('code', 'round_trip_disabled');

        // Aucun billet aller-retour créé.
        $this->assertSame(0, Ticket::count());
    }

    public function test_round_trip_sale_is_allowed_when_flag_enabled(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FLAG-ON');

        $this->makeTenant('flag-enabled-tenant', 'Tenant Avec AR', ['round_trip_sales' => true]);

        $this->httpRoundTripSale($trip, $a, $b)->assertCreated();
    }

    public function test_one_way_sale_is_not_blocked_when_round_trip_flag_disabled(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FLAG-ONEWAY');

        $this->makeTenant('flag-onway-tenant', 'Tenant OneWay', ['round_trip_sales' => false]);

        $seller = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($seller)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seats' => [1],
            'journey_type' => 'one_way',
            'passenger_name' => 'Client Simple',
            'passenger_phone' => '2250700000002',
            'amount' => 3000,
        ])->assertCreated();
    }

    public function test_admin_can_configure_feature_flags_without_losing_unknown_flags(): void
    {
        $tenant = $this->makeTenant('flag-settings-tenant', 'Tenant Paramétrable', [
            'departure_programs' => false,
            'round_trip_sales' => false,
            'future_module' => true,
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.settings.enterprise'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Enterprise')
                ->where('featureFlags.departure_programs', false)
                ->where('featureFlags.round_trip_sales', false));

        $this->actingAs($admin)
            ->post(route('admin.settings.enterprise.update'), [
                'name' => 'Tenant Paramétrable',
                'email' => 'contact@example.test',
                'phone' => '+2250700000000',
                'automatic_connection_allocation' => false,
                'connection_transfer_buffer_minutes' => 15,
                'operational_day_start_hour' => 3,
                'scheduled_trip_lookahead_hours' => 72,
                'seller_compensation_enabled' => false,
                'seller_compensation_max_amount' => 0,
                'default_vehicle_assignment_policy' => 'require_real_vehicle',
                'departure_programs' => true,
                'round_trip_sales' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $tenant->refresh();

        $this->assertTrue($tenant->departureProgramsEnabled());
        $this->assertTrue($tenant->roundTripSalesEnabled());
        $this->assertTrue($tenant->featureFlag('future_module'));
    }

    public function test_materialization_is_skipped_when_departure_programs_disabled(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'MAT-FLAG');
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);

        DepartureSchedule::create([
            'station_id' => $a->id,
            'route_id' => $trip->route_id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_time' => '08:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'default_vehicle_type_id' => $trip->vehicle->vehicle_type_id,
            'active' => true,
            'created_by' => $user->id,
        ]);

        $this->makeTenant('flag-mat-disabled', 'Tenant Sans Programmes', ['departure_programs' => false]);

        $report = app(MaterializeScheduledTrips::class)->materialize();
        $this->assertSame(0, $report['created']);
        $this->assertArrayHasKey('feature_flag_disabled', $report['errors']);
    }
}
