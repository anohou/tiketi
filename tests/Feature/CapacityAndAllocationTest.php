<?php

namespace Tests\Feature;

use App\Domain\Ticketing\DeferredSeatAllocator;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AssignRealVehicleToTrip;
use App\Services\MaterializeScheduledTrips;
use App\Services\ReturnJourneyAllocator;
use App\Services\SellRoundTripTicket;
use App\Services\TripCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class CapacityAndAllocationTest extends TestCase
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

    private function makeVehicleType(int $seats): VehicleType
    {
        return VehicleType::create(['name' => 'Bus '.$seats, 'seat_count' => $seats, 'active' => true]);
    }

    private function makeVehicle(VehicleType $type, string $identifier): Vehicle
    {
        return Vehicle::create(['identifier' => $identifier, 'vehicle_type_id' => $type->id, 'seat_count' => $type->seat_count, 'active' => true]);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function makeOpenTrip(Station $a, Station $b, int $capacity = 50): Trip
    {
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = $this->makeVehicleType($capacity);
        $vehicle = $this->makeVehicle($type, 'REEL-'.uniqid());

        return Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay()->setTime(8, 0),
            'status' => 'scheduled',
            'operational_ready' => true,
            'sales_ready' => true,
            'planned_capacity_snapshot' => $capacity,
        ]);
    }

    private function makePlannedTrip(Station $a, Station $b, int $capacity = 50): Trip
    {
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = $this->makeVehicleType($capacity);

        DepartureSchedule::create([
            'station_id' => $a->id,
            'route_id' => $route->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_time' => '08:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => $capacity,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = $this->makeUser();
        app(\App\Services\AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Test');

        return $trip;
    }

    private function sellQuantity(Trip $trip, Station $a, Station $b, array $overrides = []): array
    {
        return app(SellRoundTripTicket::class)->sell(array_merge([
            'trip' => $trip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY,
            'seat_number' => null,
            'return_mode' => null,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Passager Test',
            'passenger_phone' => '',
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
    // A. Capacité : les retours affectés comptent
    // =============================================================

    public function test_assigned_return_reduces_remaining_capacity(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b, 10);
        $returnTrip = $this->makeOpenTrip($b, $a, 10);
        $user = $this->makeUser();

        // 2 allers simples sur le voyage retour (billetterie normale).
        $this->sellQuantity($outbound, $a, $b, ['seat_number' => 1, 'seller_id' => $user->id]);
        $this->sellQuantity($outbound, $a, $b, ['seat_number' => 2, 'seller_id' => $user->id]);

        // Capacité initiale du voyage retour : 10.
        $capacity = app(TripCapacityService::class);
        $this->assertSame(10, $capacity->remainingCapacity($returnTrip));

        // Un retour ouvert affecté au voyage retour.
        $roundTrip = app(SellRoundTripTicket::class)->sell([
            'trip' => $outbound,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => 3,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Client AR',
            'passenger_phone' => '+225****0001',
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);

        app(ReturnJourneyAllocator::class)->assign($roundTrip['return'], $returnTrip, null, $user);

        // Le retour affecté (sans siège) consomme la capacité du voyage retour.
        $this->assertSame(9, $capacity->remainingCapacity($returnTrip));
    }

    public function test_assigned_returns_block_sale_when_capacity_exhausted(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeOpenTrip($a, $b, 10);
        $returnTrip = $this->makeOpenTrip($b, $a, 2);
        $user = $this->makeUser();

        // 2 retours affectés sans siège → capacité 2 épuisée.
        for ($i = 0; $i < 2; $i++) {
            $r = app(SellRoundTripTicket::class)->sell([
                'trip' => $outbound,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
                'seat_number' => 4 + $i,
                'return_mode' => TicketJourney::SELECTION_OPEN,
                'return_schedule_id' => null,
                'return_date' => null,
                'return_time' => null,
                'passenger_name' => 'Client '.$i,
                'passenger_phone' => '+225****00'.($i + 1),
                'seller_id' => $user->id,
                'station_id' => $a->id,
                'fare_calculation' => null,
                'okohi_customer_number' => null,
                'okohi_reward_id' => null,
                'okohi_transaction_id' => null,
            ]);
            app(ReturnJourneyAllocator::class)->assign($r['return'], $returnTrip, null, $user);
        }

        $this->assertSame(0, app(TripCapacityService::class)->remainingCapacity($returnTrip));

        // Une vente sur le voyage retour doit échouer (capacité épuisée).
        $newOutbound = $this->makeOpenTrip($a, $b, 10);
        $sale = [
            'trip' => $returnTrip,
            'from_station_id' => $b->id,
            'to_station_id' => $a->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY,
            'seat_number' => 1,
            'return_mode' => null,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Trop tard',
            'passenger_phone' => '',
            'seller_id' => $user->id,
            'station_id' => $b->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ];

        try {
            app(SellRoundTripTicket::class)->sell($sale);
            $this->fail('La vente doit être bloquée quand la capacité est épuisée par les retours.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('capacity_exhausted', $e->reasonCode);
        }
    }

    public function test_assigned_returns_prevent_too_small_vehicle(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        // Voyage planifié 10 places, matérialisé avec véhicule technique.
        $trip = $this->makePlannedTrip($a, $b, 10);
        $user = $this->makeUser();

        // 6 ventes quantity_only (sans siège).
        for ($i = 0; $i < 6; $i++) {
            $this->sellQuantity($trip, $a, $b, ['passenger_name' => 'Client '.$i, 'passenger_phone' => '+225****10'.$i, 'seller_id' => $user->id]);
        }

        // 3 retours affectés sans siège.
        for ($i = 0; $i < 3; $i++) {
            $r = app(SellRoundTripTicket::class)->sell([
                'trip' => $trip,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
                'seat_number' => null,
                'return_mode' => TicketJourney::SELECTION_OPEN,
                'return_schedule_id' => null,
                'return_date' => null,
                'return_time' => null,
                'passenger_name' => 'AR '.$i,
                'passenger_phone' => '+225****20'.$i,
                'seller_id' => $user->id,
                'station_id' => $a->id,
                'fare_calculation' => null,
                'okohi_customer_number' => null,
                'okohi_reward_id' => null,
                'okohi_transaction_id' => null,
            ]);
        }

        // 9 engagements au total sur le voyage.
        $this->assertSame(9, app(TripCapacityService::class)->activeEngagements($trip));

        // Un car de 8 places est trop petit.
        $small = $this->makeVehicle($this->makeVehicleType(8), 'PETIT-8');
        try {
            app(AssignRealVehicleToTrip::class)->assign($trip, $small, $user, 'Test');
            $this->fail('Un car trop petit doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('vehicle_too_small', $e->reasonCode);
        }

        // canAssign (sans effet) retourne false pour le car trop petit.
        $this->assertFalse(app(AssignRealVehicleToTrip::class)->canAssign($trip, $small));

        // Un car de 9 places passe.
        $bigEnough = $this->makeVehicle($this->makeVehicleType(9), 'SUFFISANT-9');
        $this->assertTrue(app(AssignRealVehicleToTrip::class)->canAssign($trip, $bigEnough));
        $updated = app(AssignRealVehicleToTrip::class)->assign($trip, $bigEnough, $user, 'Test');
        $this->assertSame($bigEnough->id, $updated->vehicle_id);
        $this->assertTrue($updated->isOperationalReady());
    }

    // =============================================================
    // B. Allocation différée : retours inclus
    // =============================================================

    public function test_deferred_allocator_seats_outbound_and_return_journeys(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b, 10);
        $returnTrip = $this->makeOpenTrip($b, $a, 10);
        $user = $this->makeUser();

        // 3 allers sans siège + 2 allers-retours (retours ouverts affectés sans siège).
        for ($i = 0; $i < 3; $i++) {
            $this->sellQuantity($trip, $a, $b, ['passenger_name' => 'Aller '.$i, 'passenger_phone' => '+225****30'.$i, 'seller_id' => $user->id]);
        }
        for ($i = 0; $i < 2; $i++) {
            $r = app(SellRoundTripTicket::class)->sell([
                'trip' => $trip,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
                'seat_number' => null,
                'return_mode' => TicketJourney::SELECTION_OPEN,
                'return_schedule_id' => null,
                'return_date' => null,
                'return_time' => null,
                'passenger_name' => 'AR '.$i,
                'passenger_phone' => '+225****40'.$i,
                'seller_id' => $user->id,
                'station_id' => $a->id,
                'fare_calculation' => null,
                'okohi_customer_number' => null,
                'okohi_reward_id' => null,
                'okohi_transaction_id' => null,
            ]);
            app(ReturnJourneyAllocator::class)->assign($r['return'], $returnTrip, null, $user);
        }

        // Alloue les sièges du voyage aller : 3 allers + 2 retours = 5 droits.
        $allocated = app(DeferredSeatAllocator::class)->allocate($trip);
        $this->assertCount(5, $allocated);

        // Chaque droit a un siège confirmé.
        foreach ($allocated as $journey) {
            $this->assertNotNull($journey->seat_number);
            $this->assertSame(TicketJourney::SEAT_CONFIRMED, $journey->seat_assignment_status);
        }

        // 5 occupations physiques sur le voyage aller.
        $this->assertSame(5, TripSeatOccupancy::where('trip_id', $trip->id)->count());

        // Les retours, eux, restent affectés au voyage retour SANS siège ici.
        $returnJourneys = TicketJourney::where('trip_id', $returnTrip->id)->get();
        $this->assertCount(2, $returnJourneys);
        foreach ($returnJourneys as $rj) {
            $this->assertNull($rj->seat_number);
        }

        // L'allocation du voyage retour attribue ensuite leurs sièges.
        $allocatedReturns = app(DeferredSeatAllocator::class)->allocate($returnTrip);
        $this->assertCount(2, $allocatedReturns);
        foreach ($allocatedReturns as $rj) {
            $this->assertNotNull($rj->seat_number);
        }
    }

    public function test_anonymous_passengers_are_not_grouped_together(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b, 10);
        $user = $this->makeUser();

        // 5 passagers anonymes nommés « Passager » sans téléphone.
        for ($i = 0; $i < 5; $i++) {
            $this->sellQuantity($trip, $a, $b, ['passenger_name' => 'Passager', 'passenger_phone' => '', 'seller_id' => $user->id]);
        }

        // L'allocation réussit (les anonymes ne forment PAS un groupe de 5
        // qui demanderait 5 sièges contigus impossibles sur un plan plein).
        $allocated = app(DeferredSeatAllocator::class)->allocate($trip);
        $this->assertCount(5, $allocated);
        $this->assertSame(5, TripSeatOccupancy::where('trip_id', $trip->id)->count());
    }

    // =============================================================
    // C. AssignRealVehicleToTrip : canAssign sans effet de bord
    // =============================================================

    public function test_can_assign_has_no_side_effects(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b, 10);
        $user = $this->makeUser();

        $this->sellQuantity($trip, $a, $b, ['passenger_phone' => '+225****50', 'seller_id' => $user->id]);

        $vehicle = $this->makeVehicle($this->makeVehicleType(10), 'CAR-X');
        $before = $trip->fresh()->toArray();

        $result = app(AssignRealVehicleToTrip::class)->canAssign($trip, $vehicle);
        $this->assertTrue($result);

        // AUCUNE modification : voyage, sièges, occupations, historique.
        $after = $trip->fresh()->toArray();
        $this->assertSame($before['vehicle_id'], $after['vehicle_id']);
        $this->assertFalse($trip->fresh()->isOperationalReady());
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $trip->id)->count());
        $this->assertSame(0, \App\Models\TicketJourneyAssignment::where('new_trip_id', $trip->id)->count());
    }
}
