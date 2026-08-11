<?php

namespace Tests\Feature;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AssignRealVehicleToTrip;
use App\Services\MaterializeScheduledTrips;
use App\Services\ResolvePlanningVehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

/**
 * Point 4 : le pool de véhicules est imposé côté serveur — un appel HTTP
 * direct ne peut pas injecter un véhicule hors pool.
 */
class VehiclePoolEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenantTicketing;

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

    private function makeVehicle(string $identifier, int $seats = 50, bool $active = true, ?string $insuranceUntil = null): Vehicle
    {
        $type = VehicleType::create(['name' => 'Type '.$identifier, 'seat_count' => $seats, 'active' => true]);

        return Vehicle::create([
            'identifier' => $identifier,
            'vehicle_type_id' => $type->id,
            'seat_count' => $seats,
            'active' => $active,
            'insurance_expiry_date' => $insuranceUntil ? CarbonImmutable::parse($insuranceUntil) : now()->addYear(),
        ]);
    }

    private function makeTrip(Station $a, Station $b, string $identifier, ?Vehicle $vehicle = null): Trip
    {
        $route = Route::create(['origin_station_id' => $a->id, 'destination_station_id' => $b->id, 'name' => "{$a->name}→{$b->name}", 'active' => true]);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $vehicle ??= $this->makeVehicle($identifier);

        return Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay()->setTime(8, 0),
            'status' => 'scheduled',
            'operational_ready' => false,
            'sales_ready' => false,
        ]);
    }

    private function assign(Trip $trip, Vehicle $vehicle, ?User $actor = null): void
    {
        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $vehicle, $actor, 'Test');
            $this->fail('L\'affectation aurait dû être refusée.');
        } catch (TicketingRuleViolation $e) {
            throw $e;
        }
    }

    public function test_seller_cannot_inject_vehicle_outside_station_pool(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makeTrip($a, $b, 'POOL-OUT');

        // Véhicule du pool de la gare C (pas A).
        $otherVehicle = $this->makeVehicle('POOL-CAR');
        StationVehicleAssignment::create([
            'station_id' => $c->id,
            'vehicle_id' => $otherVehicle->id,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);

        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $otherVehicle, $seller);
            $this->fail('Un vendeur ne peut pas affecter un véhicule hors du pool de sa gare.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('vehicle_not_in_pool', $e->reasonCode);
        }
    }

    public function test_admin_can_assign_out_of_pool_but_still_respects_business_rules(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makeTrip($a, $b, 'POOL-ADMIN');

        $otherVehicle = $this->makeVehicle('POOL-ADMIN-CAR');
        StationVehicleAssignment::create([
            'station_id' => $c->id,
            'vehicle_id' => $otherVehicle->id,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        // Admin : le pool n'est pas un blocage (politique d'exception conservée),
        // mais les règles métier de capacité/assurance s'appliquent toujours.
        $updated = app(AssignRealVehicleToTrip::class)->assign($trip, $otherVehicle, $admin, 'Test');
        $this->assertSame($otherVehicle->id, $updated->vehicle_id);
    }

    public function test_expired_station_assignment_is_refused(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'POOL-EXP');

        $vehicle = $this->makeVehicle('POOL-EXP-CAR');
        // Affectation expirée (se termine hier).
        StationVehicleAssignment::create([
            'station_id' => $a->id,
            'vehicle_id' => $vehicle->id,
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->subDay(),
            'active' => true,
        ]);

        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);

        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $vehicle, $seller);
            $this->fail('Une affectation expirée doit être refusée.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('vehicle_not_in_pool', $e->reasonCode);
        }
    }

    public function test_inactive_vehicle_is_refused(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'POOL-INACTIVE');

        $inactive = $this->makeVehicle('POOL-INACTIVE-CAR', 50, active: false);
        StationVehicleAssignment::create([
            'station_id' => $a->id,
            'vehicle_id' => $inactive->id,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $inactive, $admin);
            $this->fail('Un véhicule inactif doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('vehicle_inactive', $e->reasonCode);
        }
    }

    public function test_placeholder_vehicle_is_refused(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'POOL-PLACE');

        $placeholder = $this->makeVehicle('PLAN-PLACE', 50);
        $placeholder->update(['is_placeholder' => true, 'active' => false]);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $placeholder, $admin);
            $this->fail('Un véhicule technique doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('placeholder_vehicle_forbidden', $e->reasonCode);
        }
    }

    public function test_vehicle_with_expired_insurance_is_refused(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'POOL-INS');

        $uninsured = $this->makeVehicle('POOL-INS-CAR', 50, insuranceUntil: now()->subDay()->toDateString());
        StationVehicleAssignment::create([
            'station_id' => $a->id,
            'vehicle_id' => $uninsured->id,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $uninsured, $admin);
            $this->fail('Un véhicule sans assurance valide doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('vehicle_insurance_expired', $e->reasonCode);
        }
    }

    public function test_pool_vehicle_is_accepted(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'POOL-OK');

        $vehicle = $this->makeVehicle('POOL-OK-CAR', 50);
        StationVehicleAssignment::create([
            'station_id' => $a->id,
            'vehicle_id' => $vehicle->id,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);

        $updated = app(AssignRealVehicleToTrip::class)->assign($trip, $vehicle, $seller);
        $this->assertSame($vehicle->id, $updated->vehicle_id);
    }

    public function test_http_assign_vehicle_with_out_of_pool_uuid_returns_business_error(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makeTrip($a, $b, 'POOL-HTTP');

        $otherVehicle = $this->makeVehicle('POOL-HTTP-CAR');
        StationVehicleAssignment::create([
            'station_id' => $c->id,
            'vehicle_id' => $otherVehicle->id,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);
        \App\Models\UserStationAssignment::create([
            'user_id' => $seller->id,
            'station_id' => $a->id,
            'active' => true,
        ]);

        $response = $this->actingAs($seller)
            ->postJson("/seller/trips/{$trip->id}/assign-vehicle", [
                'vehicle_id' => $otherVehicle->id,
                'reason' => 'Test',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'vehicle_not_in_pool');
    }
}
