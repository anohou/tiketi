<?php

namespace Tests\Feature;

use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\TripTimingService;
use App\Services\VehiclePoolService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class AutomaticVehiclePoolRelocationTest extends TestCase
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

    private function makeVehicle(string $identifier, int $seats = 50, bool $active = true): Vehicle
    {
        $type = VehicleType::create(['name' => 'Type '.$identifier, 'seat_count' => $seats, 'active' => true]);

        return Vehicle::create([
            'identifier' => $identifier,
            'vehicle_type_id' => $type->id,
            'seat_count' => $seats,
            'active' => $active,
            'insurance_expiry_date' => now()->addYear(),
        ]);
    }

    private function makeTrip(Station $a, Station $b, Vehicle $vehicle): Trip
    {
        $route = Route::create(['origin_station_id' => $a->id, 'destination_station_id' => $b->id, 'name' => "{$a->name}→{$b->name}", 'active' => true]);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);

        return Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->setTime(8, 0),
            'status' => 'scheduled',
            'operational_ready' => true,
            'sales_ready' => true,
        ]);
    }

    public function test_vehicle_relocates_to_destination_station_pool_on_departure(): void
    {
        $stationA = $this->makeStation('Gare Abidjan', 'ABJ');
        $stationB = $this->makeStation('Gare Yamoussoukro', 'YAK');
        $vehicle = $this->makeVehicle('CAR-101');

        // Affectation initiale au pool de la Gare A
        StationVehicleAssignment::create([
            'station_id' => $stationA->id,
            'vehicle_id' => $vehicle->id,
            'valid_from' => now()->subDays(5),
            'active' => true,
        ]);

        $vehiclePoolService = app(VehiclePoolService::class);
        $today = now()->toDateString();

        // 1. Avant le départ : le car est dans le pool de Gare A, pas Gare B
        $poolA = $vehiclePoolService->listForStation($stationA, $today, isAdmin: false);
        $poolB = $vehiclePoolService->listForStation($stationB, $today, isAdmin: false);

        $this->assertTrue($poolA->contains('id', $vehicle->id));
        $this->assertFalse($poolB->contains('id', $vehicle->id));

        // 2. Départ du voyage de A vers B
        $trip = $this->makeTrip($stationA, $stationB, $vehicle);
        app(TripTimingService::class)->markDeparted($trip);

        // 3. Après le départ : le car a quitté le pool de Gare A et rejoint immédiatement le pool de Gare B
        $poolAAfter = $vehiclePoolService->listForStation($stationA, $today, isAdmin: false);
        $poolBAfter = $vehiclePoolService->listForStation($stationB, $today, isAdmin: false);

        $this->assertFalse($poolAAfter->contains('id', $vehicle->id));
        $this->assertTrue($poolBAfter->contains('id', $vehicle->id));
    }

    public function test_deactivating_vehicle_returns_it_to_general_pool(): void
    {
        $stationA = $this->makeStation('Gare Bouaké', 'BKE');
        $vehicle = $this->makeVehicle('CAR-202');

        StationVehicleAssignment::create([
            'station_id' => $stationA->id,
            'vehicle_id' => $vehicle->id,
            'valid_from' => now()->subDays(2),
            'active' => true,
        ]);

        $vehiclePoolService = app(VehiclePoolService::class);
        $today = now()->toDateString();

        $this->assertTrue($vehiclePoolService->listForStation($stationA, $today, isAdmin: false)->contains('id', $vehicle->id));

        // Le véhicule tombe en panne / passe inactif
        $vehicle->update([
            'active' => false,
            'inactive_reason' => 'Panne moteur',
        ]);

        // Les affectations de gare doivent être désactivées
        $activeAssignments = StationVehicleAssignment::where('vehicle_id', $vehicle->id)->where('active', true)->count();
        $this->assertSame(0, $activeAssignments);

        // Il n'apparaît plus dans le pool actif de la Gare A
        $this->assertFalse($vehiclePoolService->listForStation($stationA, $today, isAdmin: false)->contains('id', $vehicle->id));
    }
}
