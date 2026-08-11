<?php

namespace Tests\Feature;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\TicketJourneyAssignment;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\MaterializeScheduledTrips;
use App\Services\ReturnJourneyAllocator;
use App\Services\SellRoundTripTicket;
use App\Services\TripManifestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class ReturnPoolTest extends TestCase
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

    private function makeRoute(Station $origin, Station $destination): Route
    {
        return Route::create([
            'name' => "{$origin->name} → {$destination->name}",
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'active' => true,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function makeSchedule(Station $origin, Station $destination, string $time, int $capacity = 50): DepartureSchedule
    {
        $route = $this->makeRoute($origin, $destination);
        $type = VehicleType::create(['name' => 'Bus plan', 'seat_count' => $capacity, 'active' => true]);

        return DepartureSchedule::create([
            'station_id' => $origin->id,
            'route_id' => $route->id,
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'departure_time' => $time,
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => $capacity,
            'confirmed_return_quota' => $capacity,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ]);
    }

    private function makeOpenTrip(Station $a, Station $b): Trip
    {
        static $counter = 0;
        $counter++;
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Bus réel '.$counter, 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-REEL-'.$counter, 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

        return Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay()->setTime(8, 0),
            'status' => 'scheduled',
            'operational_ready' => true,
            'sales_ready' => true,
        ]);
    }

    private function sellRoundTrip(Trip $trip, Station $a, Station $b, array $overrides = []): array
    {
        return app(SellRoundTripTicket::class)->sell(array_merge([
            'trip' => $trip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => 1,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Passager Test',
            'passenger_phone' => '+225****0000',
            'seller_id' => $this->makeUser()->id,
            'station_id' => $a->id,
            'final_destination_station_id' => null,
            'transfer_station_id' => null,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ], $overrides));
    }

    // =============================================================
    // Rattachement automatique lors de la matérialisation nocturne
    // =============================================================

    public function test_materialization_attaches_fixed_schedule_returns(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeOpenTrip($a, $b);
        $schedule = $this->makeSchedule($b, $a, '18:00');
        // Date dans la fenêtre opérationnelle (lookahead 1h → jour courant).
        $returnDate = CarbonImmutable::now()->startOfDay()->toDateString();

        // Vente d'un aller-retour avec retour sur le programme B→A.
        $this->sellRoundTrip($trip, $a, $b, [
            'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
            'return_schedule_id' => $schedule->id,
            'return_date' => $returnDate,
        ]);

        $return = TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)->first();
        $this->assertSame(TicketJourney::STATUS_AWAITING_TRIP, $return->status);
        $this->assertNull($return->trip_id, 'Pas de voyage avant matérialisation.');

        // Matérialisation nocturne → le retour rejoint le voyage créé.
        $report = app(MaterializeScheduledTrips::class)->materialize();
        $this->assertSame(1, $report['created']);

        $tripReturn = Trip::where('departure_schedule_id', $schedule->id)->first();
        $this->assertNotNull($tripReturn);

        $return->refresh();
        $this->assertSame($tripReturn->id, $return->trip_id);
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $return->status);
        $this->assertNull($return->seat_number, 'Pas de place définitive sans car réel.');

        // Seconde exécution : idempotent, rien de nouveau.
        $report2 = app(MaterializeScheduledTrips::class)->materialize();
        $this->assertSame(0, $report2['created']);
    }

    // =============================================================
    // Affectation manuelle
    // =============================================================

    public function test_manual_assignment_of_open_return_with_seat(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b);
        $returnTrip = $this->makeOpenTrip($b, $a);

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $journey = $result['return'];
        $this->assertSame(TicketJourney::STATUS_PENDING, $journey->status);

        $user = $this->makeUser();
        $assigned = app(ReturnJourneyAllocator::class)->assign($journey, $returnTrip, 12, $user);

        $this->assertSame($returnTrip->id, $assigned->trip_id);
        $this->assertSame(12, $assigned->seat_number);
        $this->assertSame(TicketJourney::SEAT_CONFIRMED, $assigned->seat_assignment_status);
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $assigned->status);
        $this->assertSame($user->id, $assigned->assigned_by);

        // Occupation physique créée.
        $this->assertSame(1, TripSeatOccupancy::where('trip_id', $returnTrip->id)->where('seat_number', 12)->count());

        // Historique d'affectation consigné.
        $this->assertSame(1, TicketJourneyAssignment::where('ticket_journey_id', $journey->id)->count());
    }

    public function test_manual_assignment_quantity_only_without_seat(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b);
        $returnTrip = $this->makeOpenTrip($b, $a);

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $assigned = app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, null, $this->makeUser());

        $this->assertSame($returnTrip->id, $assigned->trip_id);
        $this->assertNull($assigned->seat_number);
        $this->assertSame(TicketJourney::SEAT_UNASSIGNED, $assigned->seat_assignment_status);
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $returnTrip->id)->count());
    }

    public function test_manual_assignment_rejects_incompatible_route(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $outbound = $this->makeOpenTrip($a, $b);
        $wrongTrip = $this->makeOpenTrip($a, $c);

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);

        try {
            app(ReturnJourneyAllocator::class)->assign($result['return'], $wrongTrip, null, $this->makeUser());
            $this->fail('Un voyage sur un autre trajet doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('incompatible_route', $e->reasonCode);
        }
    }

    public function test_manual_assignment_rejects_occupied_seat(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b);
        $returnTrip = $this->makeOpenTrip($b, $a);

        $r1 = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $r2 = $this->sellRoundTrip($outbound, $a, $b, ['seat_number' => 2, 'return_mode' => TicketJourney::SELECTION_OPEN]);

        $user = $this->makeUser();
        app(ReturnJourneyAllocator::class)->assign($r1['return'], $returnTrip, 5, $user);

        try {
            app(ReturnJourneyAllocator::class)->assign($r2['return'], $returnTrip, 5, $user);
            $this->fail('Un siège occupé doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('seat_taken', $e->reasonCode);
        }
    }

    // =============================================================
    // Retrait / réaffectation
    // =============================================================

    public function test_unassign_returns_journey_to_pool_and_frees_seat(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b);
        $returnTrip = $this->makeOpenTrip($b, $a);

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $journey = $result['return'];
        $user = $this->makeUser();

        app(ReturnJourneyAllocator::class)->assign($journey, $returnTrip, 7, $user);
        $unassigned = app(ReturnJourneyAllocator::class)->unassign($journey, $user, 'Voyage annulé');

        $this->assertNull($unassigned->trip_id);
        $this->assertNull($unassigned->seat_number);
        $this->assertSame(TicketJourney::STATUS_PENDING, $unassigned->status);
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $returnTrip->id)->count(), 'La place doit être libérée.');

        // Historique : le retrait consigne l'ancien voyage et la place libérée.
        $history = TicketJourneyAssignment::where('ticket_journey_id', $journey->id)
            ->whereNull('new_trip_id')
            ->orderByDesc('created_at')->first();
        $this->assertNotNull($history, 'Le retrait doit être consigné dans l’historique.');
        $this->assertSame($returnTrip->id, $history->previous_trip_id);
        $this->assertSame(7, $history->previous_seat_number);
    }

    // =============================================================
    // Manifeste
    // =============================================================

    public function test_manifest_lists_round_trip_passengers_with_seats(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b);
        $returnTrip = $this->makeOpenTrip($b, $a);

        $user = $this->makeUser();
        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, 3, $user);

        $manifest = app(TripManifestService::class)->forTrip($returnTrip);

        $this->assertCount(1, $manifest);
        $row = $manifest->first();
        $this->assertSame($result['ticket']->ticket_number, $row['ticket_number']);
        $this->assertSame(3, $row['seat_number']);
        $this->assertSame(TicketJourney::DIRECTION_RETURN, $row['direction']);
        $this->assertFalse($row['boarded']);
    }

    public function test_manifest_boarding_stats(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeOpenTrip($a, $b);

        $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 1]);
        $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 2]);

        $stats = app(TripManifestService::class)->boardingStats($trip);
        $this->assertSame(0, $stats['boarded']);
        $this->assertSame(2, $stats['total']);
    }

    // =============================================================
    // HTTP : pool des retours
    // =============================================================

    public function test_http_return_pool_index_and_assign(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b);
        $returnTrip = $this->makeOpenTrip($b, $a);
        $user = $this->makeUser();

        $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN, 'seller_id' => $user->id]);
        $journey = TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)->first();

        $this->actingAs($user)->getJson('/seller/return-pool')
            ->assertOk()
            ->assertJsonCount(1, 'journeys');

        $this->actingAs($user)->postJson('/seller/return-journeys/'.$journey->id.'/assign', [
            'trip_id' => $returnTrip->id,
            'seat_number' => 9,
        ])->assertOk()->assertJsonPath('journey.trip_id', $returnTrip->id);

        // Retrait.
        $this->actingAs($user)->deleteJson('/seller/return-journeys/'.$journey->id.'/assignment')
            ->assertOk()
            ->assertJsonPath('journey.trip_id', null);
    }
}
