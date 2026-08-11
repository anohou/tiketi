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
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AuthorizePlannedCapacitySales;
use App\Services\MaterializeScheduledTrips;
use App\Services\SellRoundTripTicket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class RoundTripSaleTest extends TestCase
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

    private function makeRoute(Station $origin, Station $destination): Route
    {
        return Route::create([
            'name' => "{$origin->name} → {$destination->name}",
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'active' => true,
        ]);
    }

    private function makeTripWithFares(Station $a, Station $b, int $fareAmount = 3000): Trip
    {
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => $fareAmount, 'is_bidirectional' => true, 'active' => true]);

        $type = VehicleType::create(['name' => 'Bus 50', 'seat_count' => 50, 'seat_configuration' => '2+2', 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-AR', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

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

    private function makeUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function baseSale(Trip $trip, Station $a, Station $b, array $overrides = []): array
    {
        return array_merge([
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
            'passenger_phone' => '+22500000000',
            'seller_id' => $this->makeUser()->id,
            'station_id' => $a->id,
            'final_destination_station_id' => null,
            'transfer_station_id' => null,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ], $overrides);
    }

    // =============================================================
    // Vente aller-retour
    // =============================================================

    public function test_round_trip_sale_creates_ticket_and_two_journeys_atomically(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);
        $this->setRoundTripDiscount(500);

        $result = app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b));

        $ticket = $result['ticket'];
        $this->assertSame(Ticket::JOURNEY_TYPE_ROUND_TRIP, $ticket->journey_type);
        // 3 000 + 3 000 avec remise globale 500.
        $this->assertSame(5500, $ticket->price);
        $this->assertSame(5500, $ticket->amount_collected);
        $this->assertSame(6000, $ticket->normal_total_amount);
        $this->assertSame(500, $ticket->round_trip_discount_amount);
        $this->assertNotNull($ticket->return_valid_until);

        // Un droit aller + un droit retour, mêmes gares inversées.
        $this->assertSame(TicketJourney::DIRECTION_OUTBOUND, $result['outbound']->direction);
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $result['outbound']->status);
        $this->assertSame($trip->id, $result['outbound']->trip_id);

        $return = $result['return'];
        $this->assertNotNull($return);
        $this->assertSame(TicketJourney::DIRECTION_RETURN, $return->direction);
        $this->assertSame($b->id, $return->from_station_id);
        $this->assertSame($a->id, $return->to_station_id);
        $this->assertSame(TicketJourney::SELECTION_OPEN, $return->selection_mode);
        $this->assertSame(TicketJourney::STATUS_PENDING, $return->status);
        $this->assertNull($return->trip_id);

        // Une seule occupation physique (aller, siège 1).
        $this->assertSame(1, TripSeatOccupancy::where('trip_id', $trip->id)->count());
    }

    public function test_round_trip_sale_open_return_has_no_date(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);

        $result = app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b));

        $this->assertNull($result['return']->desired_travel_date);
        $this->assertNull($result['return']->departure_schedule_id);
    }

    public function test_round_trip_sale_date_flexible_keeps_only_date(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);
        $date = CarbonImmutable::now()->addDays(5)->toDateString();

        $result = app(SellRoundTripTicket::class)->sell(
            $this->baseSale($trip, $a, $b, [
                'return_mode' => TicketJourney::SELECTION_DATE_FLEXIBLE,
                'return_date' => $date,
            ])
        );

        $return = $result['return'];
        $this->assertSame(TicketJourney::STATUS_READY, $return->status);
        $this->assertSame($date, $return->desired_travel_date->toDateString());
        $this->assertNull($return->departure_schedule_id);
    }

    public function test_round_trip_sale_fixed_schedule_requires_schedule_and_date(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);

        // Sans programme : refus.
        try {
            app(SellRoundTripTicket::class)->sell(
                $this->baseSale($trip, $a, $b, ['return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE])
            );
            $this->fail('Un retour à créneau précis exige un programme.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('return_schedule_required', $e->reasonCode);
        }
    }

    public function test_fixed_schedule_return_waits_for_materialization_and_consumes_quota(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $trip = $this->makeTripWithFares($a, $b);

        // Programme de retour B→A avec quota garanti de 2.
        $type = VehicleType::create(['name' => 'Bus retour', 'seat_count' => 50, 'active' => true]);
        $schedule = DepartureSchedule::create([
            'station_id' => $b->id,
            'route_id' => $route->id,
            'origin_station_id' => $b->id,
            'destination_station_id' => $a->id,
            'departure_time' => '18:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'confirmed_return_quota' => 2,
            'default_vehicle_type_id' => $type->id,
            'active' => true,
        ]);

        $returnDate = CarbonImmutable::now()->addDays(3)->toDateString();

        $user = $this->makeUser();
        $sale = $this->baseSale($trip, $a, $b, [
            'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
            'return_schedule_id' => $schedule->id,
            'return_date' => $returnDate,
            'return_time' => '18:00',
            'seller_id' => $user->id,
        ]);

        $result = app(SellRoundTripTicket::class)->sell($sale);

        $return = $result['return'];
        $this->assertSame(TicketJourney::STATUS_AWAITING_TRIP, $return->status);
        $this->assertSame($schedule->id, $return->departure_schedule_id);
        $this->assertSame($returnDate, $return->desired_travel_date->toDateString());
        $this->assertNull($return->trip_id, 'Aucun voyage lointain généré à la vente.');

        // Le quota est consommé (2 max) : la 2e vente passe, la 3e est refusée.
        app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b, [
            'seat_number' => 2,
            'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
            'return_schedule_id' => $schedule->id,
            'return_date' => $returnDate,
            'seller_id' => $user->id,
        ]));

        try {
            app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b, [
                'seat_number' => 3,
                'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
                'return_schedule_id' => $schedule->id,
                'return_date' => $returnDate,
                'seller_id' => $user->id,
            ]));
            $this->fail('Le quota de retours garantis doit être dépassé à la 3e vente.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('return_quota_exceeded', $e->reasonCode);
        }
    }

    public function test_sale_without_vehicle_creates_quantity_only_ticket(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);

        // Programme en vente sur capacité planifiée, matérialisé sans car réel.
        $type = VehicleType::create(['name' => 'Bus plan', 'seat_count' => 50, 'active' => true]);
        DepartureSchedule::create([
            'station_id' => $a->id,
            'route_id' => $route->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_time' => '08:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ]);

        app(MaterializeScheduledTrips::class)->materialize();
        $trip = Trip::first();
        $user = $this->makeUser();

        // Report explicite du car.
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Car indisponible');

        $result = app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b, [
            'seat_number' => null,
            'seller_id' => $user->id,
        ]));

        $ticket = $result['ticket'];
        $this->assertNull($ticket->seat_number);
        $this->assertNull($result['outbound']->seat_number);
        $this->assertSame(TicketJourney::SEAT_UNASSIGNED, $result['outbound']->seat_assignment_status);
        $this->assertSame(TicketJourney::STATUS_AWAITING_TRIP, $result['outbound']->status);
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $trip->id)->count(), 'Pas d’occupation physique sans siège.');
    }

    public function test_sale_requires_valid_return_mode(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);

        try {
            app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b, ['return_mode' => 'invalid']));
            $this->fail('Le mode de retour invalide doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('invalid_return_mode', $e->reasonCode);
        }
    }

    public function test_one_way_quantity_only_is_supported(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $trip = $this->makeTripWithFares($a, $b);

        $user = $this->makeUser();
        $result = app(SellRoundTripTicket::class)->sell($this->baseSale($trip, $a, $b, [
            'journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY,
            'seat_number' => null,
            'seller_id' => $user->id,
        ]));

        $this->assertNull($result['return']);
        $this->assertNull($result['ticket']->seat_number);
        $this->assertSame(3000, $result['ticket']->price);
    }

    // =============================================================
    // HTTP : POST /seller/tickets (aller-retour)
    // =============================================================

    public function test_http_sale_round_trip_open_return(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);
        $this->setRoundTripDiscount(500);
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seats' => [1],
            'journey_type' => 'round_trip',
            'return_mode' => 'open',
        ]);

        $response->assertCreated();
        $ticketId = $response->json('tickets.0.id');
        $ticket = Ticket::with(['outboundJourney', 'returnJourney'])->find($ticketId);

        $this->assertNotNull($ticket);
        $this->assertSame('round_trip', $ticket->journey_type);
        $this->assertSame(5500, $ticket->amount_collected);
        $this->assertNotNull($ticket->outboundJourney);
        $this->assertNotNull($ticket->returnJourney);
        $this->assertSame('open', $ticket->returnJourney->selection_mode);
    }

    public function test_http_sale_rejects_missing_return_mode_for_round_trip(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTripWithFares($a, $b);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/seller/tickets', [
                'trip_id' => $trip->id,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'seats' => [1],
                'journey_type' => 'round_trip',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('return_mode');
    }
}
