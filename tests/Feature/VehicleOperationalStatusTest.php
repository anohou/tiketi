<?php

namespace Tests\Feature;

use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\VehicleOperationalStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class VehicleOperationalStatusTest extends TestCase
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

    private function makeVehicle(string $identifier, bool $active = true, ?string $reason = null): Vehicle
    {
        $type = VehicleType::create(['name' => 'Type '.$identifier, 'seat_count' => 50, 'active' => true]);

        return Vehicle::create([
            'identifier' => $identifier,
            'vehicle_type_id' => $type->id,
            'seat_count' => 50,
            'active' => $active,
            'inactive_reason' => $reason,
            'insurance_expiry_date' => now()->addYear(),
        ]);
    }

    private function makeTrip(Station $a, Station $b, Vehicle $vehicle, string $status = 'scheduled'): Trip
    {
        $route = Route::create(['origin_station_id' => $a->id, 'destination_station_id' => $b->id, 'name' => "{$a->name}→{$b->name}", 'active' => true]);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);

        return Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->setTime(14, 0),
            'status' => $status,
            'operational_ready' => true,
            'sales_ready' => true,
        ]);
    }

    public function test_inactive_vehicle_reports_inactive_status(): void
    {
        $vehicle = $this->makeVehicle('CAR-PANNE', active: false, reason: 'Moteur HS');
        $service = app(VehicleOperationalStatusService::class);

        $status = $service->forVehicle($vehicle);

        $this->assertSame(VehicleOperationalStatusService::STATUS_INACTIVE, $status['status']);
        $this->assertSame('Moteur HS', $status['inactive_reason']);
        $this->assertNull($status['trip']);
    }

    public function test_vehicle_on_departed_trip_reports_in_transit_status(): void
    {
        $a = $this->makeStation('Abidjan', 'ABJ');
        $b = $this->makeStation('Yamoussoukro', 'YAK');
        $vehicle = $this->makeVehicle('CAR-TRANSIT');
        $trip = $this->makeTrip($a, $b, $vehicle, status: 'departed');

        $service = app(VehicleOperationalStatusService::class);
        $status = $service->forVehicle($vehicle);

        $this->assertSame(VehicleOperationalStatusService::STATUS_IN_TRANSIT, $status['status']);
        $this->assertNotNull($status['trip']);
        $this->assertSame($trip->id, $status['trip']['id']);
        $this->assertSame('Abidjan', $status['trip']['origin']);
        $this->assertSame('Yamoussoukro', $status['trip']['destination']);
    }

    public function test_vehicle_on_scheduled_trip_reports_scheduled_status(): void
    {
        $a = $this->makeStation('Abidjan', 'ABJ');
        $b = $this->makeStation('Bouaké', 'BKE');
        $vehicle = $this->makeVehicle('CAR-SCHED');
        $trip = $this->makeTrip($a, $b, $vehicle, status: 'scheduled');

        $service = app(VehicleOperationalStatusService::class);
        $status = $service->forVehicle($vehicle);

        $this->assertSame(VehicleOperationalStatusService::STATUS_SCHEDULED, $status['status']);
        $this->assertNotNull($status['trip']);
        $this->assertSame($trip->id, $status['trip']['id']);
    }

    public function test_available_vehicle_reports_available_status(): void
    {
        $vehicle = $this->makeVehicle('CAR-FREE');
        $service = app(VehicleOperationalStatusService::class);

        $status = $service->forVehicle($vehicle);

        $this->assertSame(VehicleOperationalStatusService::STATUS_AVAILABLE, $status['status']);
        $this->assertNull($status['trip']);
    }

    public function test_summary_totals_are_accurate(): void
    {
        $a = $this->makeStation('Abidjan', 'ABJ');
        $b = $this->makeStation('Yamoussoukro', 'YAK');

        $v1 = $this->makeVehicle('CAR-1');
        $v2 = $this->makeVehicle('CAR-2');
        $v3 = $this->makeVehicle('CAR-3');
        $v4 = $this->makeVehicle('CAR-4', active: false, reason: 'Revision');

        $this->makeTrip($a, $b, $v1, status: 'departed');
        $this->makeTrip($a, $b, $v2, status: 'scheduled');

        $service = app(VehicleOperationalStatusService::class);
        $summary = $service->summaryForVehicles(collect([$v1, $v2, $v3, $v4]));

        $this->assertSame(1, $summary[VehicleOperationalStatusService::STATUS_IN_TRANSIT]);
        $this->assertSame(1, $summary[VehicleOperationalStatusService::STATUS_SCHEDULED]);
        $this->assertSame(1, $summary[VehicleOperationalStatusService::STATUS_AVAILABLE]);
        $this->assertSame(1, $summary[VehicleOperationalStatusService::STATUS_INACTIVE]);
        $this->assertSame(4, $summary['total']);
    }

    public function test_station_vehicle_assignments_page_loads_operational_summary(): void
    {
        $station = $this->makeStation('Abidjan', 'ABJ');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $response = $this->actingAs($admin)->get('/fleet/station-vehicle-assignments');

        $response->assertStatus(200);
    }
}
