<?php

namespace Tests\Feature;

use App\Domain\Ticketing\DeferredSeatAllocator;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ReturnJourneyAllocator;
use App\Services\SellRoundTripTicket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

/**
 * Point D : l'allocation d'un retour ne doit JAMAIS écraser les champs
 * historiques de l'aller (seat_number, vehicle_id, boarding_group).
 */
class ReturnDoesNotOverwriteOutboundTest extends TestCase
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

    private function makeRoute(Station $a, Station $b): Route
    {
        return Route::create([
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'name' => "{$a->name} → {$b->name}",
            'active' => true,
        ]);
    }

    private function makeTrip(Station $a, Station $b, string $identifier, int $seats = 50): Trip
    {
        static $counter = 0;
        $counter++;
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Type '.$counter, 'seat_count' => $seats, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => $identifier, 'vehicle_type_id' => $type->id, 'seat_count' => $seats, 'active' => true]);

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

    private function sellRoundTrip(Trip $trip, Station $a, Station $b, array $overrides = []): array
    {
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);

        $base = [
            'trip' => $trip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'passenger_name' => 'Passager Test',
            'passenger_phone' => '2250700000001',
            'fare_amount' => 3000,
            'journey_type' => 'round_trip',
            'return_mode' => 'open',
            'seat_number' => 1,
            'quantity' => 1,
            'seller_id' => $user->id,
            'station_id' => $a->id,
        ];

        return app(SellRoundTripTicket::class)->sell(array_replace($base, $overrides));
    }

    public function test_allocating_return_never_overwrites_outbound_legacy_fields(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'OUT-REEL-D', 50);
        $returnTrip = $this->makeTrip($b, $a, 'RET-REEL-D', 50);

        // Vente aller-retour SANS siège (quantité) : l'aller est en attente.
        $result = $this->sellRoundTrip($outboundTrip, $a, $b, [
            'seat_number' => null,
            'return_mode' => TicketJourney::SELECTION_OPEN,
        ]);
        $ticket = $result['ticket'];
        $return = $result['return'];

        $this->assertNull($ticket->seat_number);

        // 1. Allocation de l'ALLER : les champs historiques du billet sont
        // synchronisés (compatibilité outbound uniquement).
        app(DeferredSeatAllocator::class)->allocate($outboundTrip);

        $ticket->refresh();
        $this->assertNotNull($ticket->seat_number, 'L\'aller alloué doit renseigner le siège historique.');
        $outboundSeat = $ticket->seat_number;
        $outboundVehicle = $ticket->vehicle_id;
        $this->assertSame($outboundTrip->vehicle_id, $outboundVehicle);

        // 2. Affectation du retour au voyage retour, puis allocation.
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, null, $user);
        app(DeferredSeatAllocator::class)->allocate($returnTrip);

        // 3. L'ALLER n'a PAS changé.
        $ticket->refresh();
        $this->assertSame($outboundSeat, $ticket->seat_number, 'Le siège historique de l\'aller ne doit pas bouger.');
        $this->assertSame($outboundVehicle, $ticket->vehicle_id, 'Le véhicule historique de l\'aller ne doit pas bouger.');

        // 4. Le RETOUR possède son propre siège et véhicule (peut être le même
        // numéro sur un autre car — ce qui compte, c'est l'indépendance).
        $return->refresh();
        $this->assertNotNull($return->seat_number);
        $this->assertSame($returnTrip->vehicle_id, $return->vehicle_id);

        // 5. L'occupation du retour référence le droit retour.
        $occupancy = TripSeatOccupancy::where('trip_id', $returnTrip->id)
            ->where('seat_number', $return->seat_number)
            ->first();
        $this->assertNotNull($occupancy);
        $this->assertSame($return->id, $occupancy->ticket_journey_id);
    }

    public function test_reallocating_is_idempotent_and_creates_no_double_occupancy(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'OUT-IDEMP', 50);
        $returnTrip = $this->makeTrip($b, $a, 'RET-IDEMP', 50);

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, [
            'seat_number' => null,
            'return_mode' => TicketJourney::SELECTION_OPEN,
        ]);
        $return = $result['return'];

        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, null, $user);

        // Double allocation du retour : idempotent.
        app(DeferredSeatAllocator::class)->allocate($returnTrip);
        $occupanciesAfterFirst = TripSeatOccupancy::where('trip_id', $returnTrip->id)->count();

        $return->refresh();
        $seatAfterFirst = $return->seat_number;

        app(DeferredSeatAllocator::class)->allocate($returnTrip);
        $occupanciesAfterSecond = TripSeatOccupancy::where('trip_id', $returnTrip->id)->count();

        $return->refresh();
        $this->assertSame($seatAfterFirst, $return->seat_number);
        $this->assertSame($occupanciesAfterFirst, $occupanciesAfterSecond, 'Aucune double occupation après réallocation.');
    }

    public function test_occupancy_references_the_journey(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'OUT-OCC', 50);
        $returnTrip = $this->makeTrip($b, $a, 'RET-OCC', 50);

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, [
            'seat_number' => null,
            'return_mode' => TicketJourney::SELECTION_OPEN,
        ]);
        $return = $result['return'];

        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, null, $user);
        app(DeferredSeatAllocator::class)->allocate($returnTrip);

        $occupancy = TripSeatOccupancy::where('trip_id', $returnTrip->id)->first();
        $this->assertNotNull($occupancy);
        $this->assertSame($return->id, $occupancy->ticket_journey_id, 'L\'occupation doit référencer le DROIT retour.');
    }
}
