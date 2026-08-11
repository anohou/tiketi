<?php

namespace Tests\Feature;

use App\Domain\Ticketing\EvaluateTripSalesReadiness;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Domain\Trips\InvalidTripTransition;
use App\Domain\Trips\TripStateMachine;
use App\Models\DepartureSchedule;
use App\Models\DepartureScheduleException;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AssignRealVehicleToTrip;
use App\Services\AuthorizePlannedCapacitySales;
use App\Services\DepartureScheduleCalendar;
use App\Services\DeferredSeatAllocator;
use App\Services\MaterializeScheduledTrips;
use App\Services\ResolvePlanningVehicle;
use App\Services\TripCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class DepartureScheduleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenantTicketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantTicketingTablesExist();

        // Fenêtre opérationnelle maîtrisée pour les tests : lookahead 1 h
        // ⇒ la fenêtre ne couvre que le jour courant, ce qui rend les
        // assertions de comptage déterministes.
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

    private function makeRoute(Station $origin, Station $destination): Route
    {
        return Route::create([
            'name' => "{$origin->name} → {$destination->name}",
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'active' => true,
        ]);
    }

    private function makeVehicleType(int $seats = 50): VehicleType
    {
        return VehicleType::create([
            'name' => "Bus {$seats} places",
            'seat_count' => $seats,
            'seat_configuration' => '2+2',
            'active' => true,
        ]);
    }

    private function makeVehicle(VehicleType $type, string $identifier = 'BUS-01', bool $placeholder = false): Vehicle
    {
        return Vehicle::create([
            'identifier' => $identifier,
            'vehicle_type_id' => $type->id,
            'seat_count' => $type->seat_count,
            'active' => ! $placeholder,
            'is_placeholder' => $placeholder,
            'maker' => 'Test',
        ]);
    }

    private function makeSchedule(Station $station, Route $route, VehicleType $type, array $overrides = []): DepartureSchedule
    {
        return DepartureSchedule::create(array_merge([
            'station_id' => $station->id,
            'route_id' => $route->id,
            'origin_station_id' => $station->id,
            'destination_station_id' => $route->destination_station_id,
            'departure_time' => '08:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6],
            'valid_from' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'valid_until' => null,
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'default_vehicle_type_id' => $type->id,
            'booking_type' => 'seat_assignment',
            'sales_control' => 'open',
            'active' => true,
        ], $overrides));
    }

    // =============================================================
    // Calendrier
    // =============================================================

    public function test_calendar_returns_occurrence_only_on_configured_days(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        // Circule uniquement le lundi (1) et le samedi (6).
        $schedule = $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 6]]);

        $calendar = app(DepartureScheduleCalendar::class);

        // Trouver un lundi et un mardi proches.
        $monday = CarbonImmutable::now()->next(CarbonImmutable::MONDAY);
        $tuesday = $monday->addDay();

        $this->assertNotEmpty($calendar->occurrencesForDate($schedule, $monday));
        $this->assertEmpty($calendar->occurrencesForDate($schedule, $tuesday));
        $this->assertNotEmpty($calendar->occurrencesForDate($schedule, $monday->addDays(5))); // samedi
    }

    public function test_calendar_respects_validity_period(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $schedule = $this->makeSchedule($station, $route, $type, [
            'valid_from' => CarbonImmutable::now()->addDays(5)->toDateString(),
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
        ]);

        $calendar = app(DepartureScheduleCalendar::class);

        $this->assertEmpty($calendar->occurrencesForDate($schedule, CarbonImmutable::now()->addDay()));
        $this->assertNotEmpty($calendar->occurrencesForDate($schedule, CarbonImmutable::now()->addDays(6)));
    }

    public function test_exception_cancels_only_the_affected_date(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $schedule = $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        $monday = CarbonImmutable::now()->next(CarbonImmutable::MONDAY);

        DepartureScheduleException::create([
            'departure_schedule_id' => $schedule->id,
            'service_date' => $monday->toDateString(),
            'type' => DepartureScheduleException::TYPE_CANCELLED,
            'reason' => 'Test',
        ]);

        $calendar = app(DepartureScheduleCalendar::class);

        $this->assertEmpty($calendar->occurrencesForDate($schedule, $monday));
        $this->assertNotEmpty($calendar->occurrencesForDate($schedule, $monday->addDay()));
    }

    public function test_exception_changes_time_and_capacity_for_the_date(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $schedule = $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        $monday = CarbonImmutable::now()->next(CarbonImmutable::MONDAY);

        DepartureScheduleException::create([
            'departure_schedule_id' => $schedule->id,
            'service_date' => $monday->toDateString(),
            'type' => DepartureScheduleException::TYPE_CAPACITY_CHANGED,
            'replacement_capacity' => 35,
            'reason' => 'Car plus petit',
        ]);

        $occurrences = app(DepartureScheduleCalendar::class)->occurrencesForDate($schedule, $monday);

        $this->assertCount(1, $occurrences);
        $this->assertSame(35, $occurrences->first()['capacity']);
        $this->assertSame('08:00', $occurrences->first()['departure_time']->format('H:i'));
    }

    public function test_consulting_90_days_calendar_creates_no_trip(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $schedule = $this->makeSchedule($station, $route, $type);

        $calendar = app(DepartureScheduleCalendar::class);
        $day = CarbonImmutable::now()->addDay();
        $count = 0;

        for ($i = 0; $i < 90; $i++) {
            $count += $calendar->occurrencesForDate($schedule, $day->addDay())->count();
        }

        $this->assertGreaterThan(0, $count);
        $this->assertSame(0, Trip::count(), 'Aucun voyage ne doit être créé par une simple consultation.');
    }

    public function test_existing_schedule_can_be_updated_without_being_detected_as_its_own_duplicate(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $destination = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $destination);
        $type = $this->makeVehicleType();
        $schedule = $this->makeSchedule($station, $route, $type, [
            'valid_from' => '2026-08-11',
            'sales_control' => 'closed',
        ]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)
            ->put(route('admin.departure-schedules.update', $schedule->id), [
                'station_id' => $station->id,
                'route_id' => $route->id,
                'origin_station_id' => $station->id,
                'destination_station_id' => $destination->id,
                'departure_time' => '08:00',
                'days_of_week' => [1, 2, 3, 4, 5, 6],
                'valid_from' => '2026-08-11',
                'valid_until' => null,
                'timezone' => 'Africa/Abidjan',
                'planned_capacity' => 50,
                'confirmed_return_quota' => null,
                'default_vehicle_type_id' => $type->id,
                'vehicle_assignment_policy' => null,
                'booking_type' => 'seat_assignment',
                'sales_control' => 'closed',
                'allows_open_connections' => true,
                'automatic_connection_allocation' => false,
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('departure_schedules', [
            'id' => $schedule->id,
            'valid_from' => '2026-08-11 00:00:00',
            'sales_control' => 'closed',
            'allows_open_connections' => true,
        ]);
    }

    public function test_departure_schedule_menu_displays_the_shared_schedule_count(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $destination = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $destination);
        $type = $this->makeVehicleType();
        $this->makeSchedule($station, $route, $type);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.departure-schedules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/DepartureSchedules/Index')
                ->where('stats.departureSchedules', 1));
    }

    // =============================================================
    // Matérialisation nocturne
    // =============================================================

    public function test_materialization_creates_occurrences_with_placeholder_vehicle(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(55);
        $schedule = $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'planned_capacity' => 55,
        ]);

        $report = app(MaterializeScheduledTrips::class)->materialize();

        $this->assertSame(1, $report['created'], 'Un seul jour opérationnel dans la fenêtre courte.');

        $trip = Trip::first();
        $this->assertNotNull($trip);
        $this->assertSame($schedule->id, $trip->departure_schedule_id);
        $this->assertTrue($trip->hasPlaceholderVehicle(), 'Le voyage doit porter un véhicule technique.');
        $this->assertSame(55, $trip->capacity());
        $this->assertSame(55, $trip->planned_capacity_snapshot);
        $this->assertFalse($trip->isSalesReady(), 'sales_ready doit rester false après matérialisation.');
        $this->assertFalse($trip->isOperationalReady());
        $this->assertSame('require_real_vehicle', $trip->vehiclePolicy());
        $this->assertSame('scheduled', $trip->status);
    }

    public function test_materialization_is_idempotent(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        $service = app(MaterializeScheduledTrips::class);
        $service->materialize();
        $report = $service->materialize();

        $this->assertSame(1, Trip::count(), 'Deux exécutions ne créent qu’une occurrence par programme et date.');
        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['skipped']);
    }

    public function test_materialization_uses_correct_timezone(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            // Nairobi = UTC+3 : l'heure locale 08:30 est stockée 05:30 UTC.
            'timezone' => 'Africa/Nairobi',
            'departure_time' => '08:30',
        ]);

        app(MaterializeScheduledTrips::class)->materialize();

        $trip = Trip::first();
        $this->assertSame('05:30', $trip->departure_at->setTimezone('UTC')->format('H:i'));
        $this->assertSame('08:30', $trip->departure_at->setTimezone('Africa/Nairobi')->format('H:i'));
    }

    public function test_materialization_skips_exception_cancelled_date(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $schedule = $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        $operationalDay = CarbonImmutable::parse(OperationalSetting::current()->operationalDayStartHour() > CarbonImmutable::now()->hour
            ? CarbonImmutable::now()->toDateString()
            : CarbonImmutable::now()->toDateString());

        DepartureScheduleException::create([
            'departure_schedule_id' => $schedule->id,
            'service_date' => $operationalDay->toDateString(),
            'type' => DepartureScheduleException::TYPE_SUSPENDED,
        ]);

        app(MaterializeScheduledTrips::class)->materialize();

        $this->assertSame(0, Trip::count(), 'Un jour suspendu ne doit pas être matérialisé.');
    }

    public function test_materialization_reports_failure_when_no_vehicle_type(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        // Supprime le type pour forcer l'échec de la résolution du véhicule.
        VehicleType::query()->delete();

        $report = app(MaterializeScheduledTrips::class)->materialize();

        $this->assertSame(0, $report['created']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, Trip::count(), 'Aucune occurrence incorrecte ne doit être créée.');
    }

    // =============================================================
    // Politiques de vente
    // =============================================================

    public function test_require_real_vehicle_policy_blocks_sales_with_placeholder(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType();
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();

        $decision = app(EvaluateTripSalesReadiness::class)->evaluate($trip);

        $this->assertFalse($decision->allowed);
        $this->assertSame('real_vehicle_required', $decision->reasonCode);
    }

    public function test_allow_planned_capacity_requires_explicit_deferral(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'planned_capacity' => 50,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();

        // Après matérialisation : toujours non vendable.
        $decision = app(EvaluateTripSalesReadiness::class)->evaluate($trip);
        $this->assertFalse($decision->allowed);
        $this->assertSame('planned_capacity_not_authorized', $decision->reasonCode);

        // Report explicite : devient vendable.
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Car indisponible ce matin');

        $trip->refresh();
        $this->assertTrue($trip->isSalesReady());
        $this->assertSame($user->id, $trip->vehicle_assignment_deferred_by);
        $this->assertSame('Car indisponible ce matin', $trip->vehicle_assignment_deferred_reason);
        $this->assertNotNull($trip->vehicle_assignment_deferred_at);

        $decision = app(EvaluateTripSalesReadiness::class)->evaluate($trip);
        $this->assertTrue($decision->allowed);
    }

    public function test_deferral_rejected_without_planned_capacity(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'planned_capacity' => null,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->expectException(TicketingRuleViolation::class);
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Test');
    }

    // =============================================================
    // Capacité
    // =============================================================

    public function test_capacity_service_counts_engagements_without_seat(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'planned_capacity' => 50,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Test');

        // Ventes quantity_only : 10 billets sans siège.
        for ($i = 0; $i < 10; $i++) {
            Ticket::create([
                'ticket_number' => 'TKT-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'from_station_id' => $station->id,
                'to_station_id' => $dest->id,
                'seat_number' => null,
                'price' => 5000,
                'seller_id' => $user->id,
                'station_id' => $station->id,
                'status' => 'issued',
                'qr_code' => 'QR-TEST-'.$i,
            ]);
        }

        $capacity = app(TripCapacityService::class);

        $this->assertSame(10, $capacity->activeEngagements($trip));
        $this->assertSame(40, $capacity->remainingCapacity($trip));
    }

    // =============================================================
    // Affectation du car réel + allocation différée
    // =============================================================

    public function test_assign_real_vehicle_replaces_placeholder_and_allocates_seats(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'planned_capacity' => 50,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Test');

        // 10 ventes quantity_only sans siège.
        $tickets = collect();
        for ($i = 0; $i < 10; $i++) {
            $tickets->push(Ticket::create([
                'ticket_number' => 'TKT-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'from_station_id' => $station->id,
                'to_station_id' => $dest->id,
                'seat_number' => null,
                'price' => 5000,
                'seller_id' => $user->id,
                'station_id' => $station->id,
                'status' => 'issued',
                'qr_code' => 'QR-TEST-'.$i,
            ]));
        }

        $realVehicle = $this->makeVehicle($type, 'CAR-REEL-01');

        $updated = app(AssignRealVehicleToTrip::class)->assign($trip, $realVehicle, $user, 'Car arrivé');

        $this->assertSame($realVehicle->id, $updated->vehicle_id);
        $this->assertTrue($updated->isOperationalReady());
        $this->assertTrue($updated->isSalesReady());
        $this->assertGreaterThan(0, $updated->seat_assignment_version);

        // Les 10 billets ont maintenant un siège confirmé + une occupation.
        $this->assertSame(10, Ticket::where('trip_id', $trip->id)->whereNotNull('seat_number')->count());
        $this->assertSame(10, \App\Models\TripSeatOccupancy::where('trip_id', $trip->id)->count());

        // Les sièges attribués sont uniques.
        $seatNumbers = Ticket::where('trip_id', $trip->id)->pluck('seat_number')->sort()->values();
        $this->assertSame(10, $seatNumbers->unique()->count());
    }

    public function test_assign_vehicle_rejects_placeholder_and_small_vehicle(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        // Refus d'affecter un autre véhicule technique comme car réel.
        $placeholder = $this->makeVehicle($type, 'PLAN-BUS-99', true);
        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $placeholder, $user);
            $this->fail('Un véhicule technique ne doit jamais être affecté comme car réel.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('placeholder_vehicle_forbidden', $e->reasonCode);
        }

        // Refus d'un car trop petit pour les engagements existants.
        $schedule2 = $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'vehicle_assignment_policy' => 'allow_planned_capacity',
        ]);
        app(MaterializeScheduledTrips::class)->materialize();
        $trip2 = Trip::where('departure_schedule_id', $schedule2->id)->first();
        $this->assertNotNull($trip2);
        app(AuthorizePlannedCapacitySales::class)->authorize($trip2, $user, 'Test');

        for ($i = 0; $i < 10; $i++) {
            Ticket::create([
                'ticket_number' => 'TKT-SMALL-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'trip_id' => $trip2->id,
                'vehicle_id' => $trip2->vehicle_id,
                'from_station_id' => $station->id,
                'to_station_id' => $dest->id,
                'seat_number' => null,
                'price' => 5000,
                'seller_id' => $user->id,
                'station_id' => $station->id,
                'status' => 'issued',
            ]);
        }

        $smallType = $this->makeVehicleType(8);
        $smallVehicle = $this->makeVehicle($smallType, 'CAR-PETIT');

        try {
            app(AssignRealVehicleToTrip::class)->assign($trip2, $smallVehicle, $user);
            $this->fail('Un car trop petit doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('vehicle_too_small', $e->reasonCode);
        }
    }

    // =============================================================
    // Barrières opérationnelles
    // =============================================================

    public function test_boarding_and_departure_rejected_with_placeholder_vehicle(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();

        // Départ interdit avec véhicule technique.
        try {
            app(TripStateMachine::class)->transition($trip, 'boarding', null, 'test');
            $this->fail('Le passage à boarding doit être refusé avec un véhicule technique.');
        } catch (InvalidTripTransition $e) {
            $this->assertStringContainsString('car réel', $e->getMessage());
        }
    }

    public function test_deferred_seat_allocator_keeps_groups_contiguous(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        // Famille de 3 personnes (même téléphone).
        for ($i = 0; $i < 3; $i++) {
            Ticket::create([
                'ticket_number' => 'TKT-GRP-'.$i,
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'from_station_id' => $station->id,
                'to_station_id' => $dest->id,
                'seat_number' => null,
                'price' => 5000,
                'seller_id' => $user->id,
                'station_id' => $station->id,
                'status' => 'issued',
                'passenger_name' => 'Membre '.$i,
                'passenger_phone' => '+2250700000000',
            ]);
        }

        $realVehicle = $this->makeVehicle($type, 'CAR-FAMILLE');
        app(AssignRealVehicleToTrip::class)->assign($trip, $realVehicle, $user);

        $seats = Ticket::where('trip_id', $trip->id)->orderBy('seat_number')->pluck('seat_number')->values()->all();

        // Les 3 sièges doivent être contigus.
        $this->assertCount(3, $seats);
        $this->assertSame($seats[0] + 1, $seats[1]);
        $this->assertSame($seats[1] + 1, $seats[2]);
    }

    // =============================================================
    // HTTP : tableau des départs (seller)
    // =============================================================

    public function test_departure_board_index_renders_inertia_page(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        app(MaterializeScheduledTrips::class)->materialize();

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)
            ->get(route('seller.departure-board.index', $station->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/DepartureBoard')
                ->has('trips', 1)
                ->where('station.id', $station->id));
    }

    public function test_departure_board_defer_requires_reason(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'planned_capacity' => 50,
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)
            ->post(route('seller.departure-board.defer-vehicle-assignment', $trip->id), [])
            ->assertSessionHasErrors('reason');

        // Avec motif : le report est accepté et audité.
        $this->actingAs($admin)
            ->post(route('seller.departure-board.defer-vehicle-assignment', $trip->id), [
                'reason' => 'Car en maintenance ce matin',
            ])
            ->assertOk()
            ->assertJsonPath('trip.sales_ready', true)
            ->assertJsonPath('trip.deferred_reason', 'Car en maintenance ce matin');

        $this->assertDatabaseHas('trips', [
            'id' => $trip->id,
            'sales_ready' => true,
            'vehicle_assignment_deferred_by' => $admin->id,
            'vehicle_assignment_deferred_reason' => 'Car en maintenance ce matin',
        ]);
    }

    public function test_departure_board_assign_vehicle_rejects_placeholder(): void
    {
        $station = $this->makeStation('Gare A', 'A');
        $dest = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($station, $dest);
        $type = $this->makeVehicleType(50);
        $this->makeSchedule($station, $route, $type, ['days_of_week' => [1, 2, 3, 4, 5, 6, 7]]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);
        $placeholder = $this->makeVehicle($type, 'PLAN-BUS-X', true);

        $this->actingAs($admin)
            ->post(route('seller.departure-board.assign-vehicle', $trip->id), [
                'vehicle_id' => $placeholder->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'placeholder_vehicle_forbidden');

        // Le car réel est accepté : ventes ouvertes + opérationnel.
        $real = $this->makeVehicle($type, 'CAR-VALIDE');
        $this->actingAs($admin)
            ->post(route('seller.departure-board.assign-vehicle', $trip->id), [
                'vehicle_id' => $real->id,
            ])
            ->assertOk()
            ->assertJsonPath('trip.vehicle.id', $real->id)
            ->assertJsonPath('trip.operational_ready', true)
            ->assertJsonPath('trip.sales_ready', true);
    }
}
