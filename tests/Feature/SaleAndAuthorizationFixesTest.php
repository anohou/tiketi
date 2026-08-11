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
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AuthorizePlannedCapacitySales;
use App\Services\MaterializeScheduledTrips;
use App\Services\ReturnJourneyAllocator;
use App\Services\SellRoundTripTicket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class SaleAndAuthorizationFixesTest extends TestCase
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

    private function makeUser(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'active' => true]);
    }

    private function makePlannedTrip(Station $a, Station $b, int $capacity = 50): Trip
    {
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Vente type', 'seat_count' => $capacity, 'active' => true]);

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

        return $trip;
    }

    // =============================================================
    // D. Vente aller simple HTTP sans siège (quantity)
    // =============================================================

    public function test_http_one_way_quantity_without_seats_sells_with_policy(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b);
        $user = $this->makeUser();
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Test');

        $response = $this->actingAs($user)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'quantity' => 2,
            'journey_type' => 'one_way',
            'amount' => 6000,
        ]);

        $response->assertCreated()
            ->assertJsonCount(2, 'ticket_ids');

        // Chaque billet a son propre QR et aucun siège (vérifié en base).
        $tickets = Ticket::where('trip_id', $trip->id)->get();
        $this->assertCount(2, $tickets);
        foreach ($tickets as $ticket) {
            $this->assertStringStartsWith('TIKETI2|', $ticket->qrPayloadString());
            $this->assertNull($ticket->seat_number);
        }
    }

    public function test_http_quantity_sale_rejects_wrong_amount(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b);
        $user = $this->makeUser();
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Test');

        $this->actingAs($user)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'quantity' => 2,
            'journey_type' => 'one_way',
            'amount' => 9999,
        ])->assertStatus(422);
    }

    public function test_http_quantity_sale_refused_when_sales_not_authorized(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b);
        $user = $this->makeUser();

        // sales_ready = false : la politique TripSalesPolicy doit bloquer.
        $this->actingAs($user)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'quantity' => 1,
            'journey_type' => 'one_way',
        ])->assertStatus(403);

        $this->assertSame(0, Ticket::count());
    }

    public function test_http_sale_with_seats_when_vehicle_known(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Réel', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'REEL-V', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay()->setTime(8, 0),
            'status' => 'scheduled',
            'operational_ready' => true,
            'sales_ready' => true,
        ]);
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seats' => [3],
            'journey_type' => 'one_way',
            'amount' => 3000,
        ]);

        $response->assertCreated();
        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $this->assertSame(3, $ticket->seat_number);
        $this->assertSame($vehicle->id, $ticket->vehicle_id);
    }

    // =============================================================
    // F. canAuthorize() sans effet de bord
    // =============================================================

    public function test_can_authorize_has_no_side_effects(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b);
        $user = $this->makeUser();

        $before = $trip->fresh()->toArray();
        $result = app(AuthorizePlannedCapacitySales::class)->canAuthorize($trip, $user);
        $this->assertTrue($result);

        $after = $trip->fresh()->toArray();
        $this->assertSame($before['sales_ready'], $after['sales_ready']);
        $this->assertNull($after['vehicle_assignment_deferred_at']);
        $this->assertNull($after['opened_at']);
    }

    public function test_authorize_is_explicit_and_audited(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makePlannedTrip($a, $b);
        $user = $this->makeUser();

        $updated = app(AuthorizePlannedCapacitySales::class)->authorize($trip, $user, 'Car bloqué à la gare');

        $this->assertTrue($updated->isSalesReady());
        $this->assertSame($user->id, $updated->vehicle_assignment_deferred_by);
        $this->assertSame('Car bloqué à la gare', $updated->vehicle_assignment_deferred_reason);
    }

    // =============================================================
    // E. Vendeur d'une autre gare refusé (tableau des départs)
    // =============================================================

    public function test_http_quantity_sale_by_seller_of_another_station_forbidden(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makePlannedTrip($a, $b);
        $admin = $this->makeUser();
        app(AuthorizePlannedCapacitySales::class)->authorize($trip, $admin, 'Test');

        $otherSeller = $this->makeUser('seller');
        UserStationAssignment::create([
            'user_id' => $otherSeller->id,
            'station_id' => $c->id,
            'active' => true,
        ]);

        $this->actingAs($otherSeller)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'quantity' => 1,
            'journey_type' => 'one_way',
        ])->assertStatus(403);
    }

    // =============================================================
    // E. Tableau des départs : vendeur d'une autre gare → 403
    // =============================================================

    public function test_seller_of_another_station_cannot_view_departure_board(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $this->makePlannedTrip($a, $b);

        $otherSeller = $this->makeUser('seller');
        UserStationAssignment::create([
            'user_id' => $otherSeller->id,
            'station_id' => $c->id,
            'active' => true,
        ]);

        // Consultation du tableau de la gare A → 403.
        $this->actingAs($otherSeller)
            ->getJson("/seller/stations/{$a->id}/departure-board")
            ->assertForbidden();
    }

    public function test_seller_of_another_station_cannot_view_return_occurrences(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $this->makePlannedTrip($a, $b);
        $schedule = DepartureSchedule::where('origin_station_id', $a->id)->firstOrFail();

        $otherSeller = $this->makeUser('seller');
        UserStationAssignment::create([
            'user_id' => $otherSeller->id,
            'station_id' => $c->id,
            'active' => true,
        ]);

        $this->actingAs($otherSeller)
            ->getJson(route('seller.departure-schedules.return-occurrences', [
                'schedule' => $schedule->id,
                'from' => now()->toDateString(),
                'to' => now()->addDays(2)->toDateString(),
            ]))
            ->assertForbidden();
    }

    public function test_seller_of_another_station_cannot_assign_vehicle(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makePlannedTrip($a, $b);
        $type = VehicleType::create(['name' => 'Réel', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'REEL-E', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

        $otherSeller = $this->makeUser('seller');
        UserStationAssignment::create([
            'user_id' => $otherSeller->id,
            'station_id' => $c->id,
            'active' => true,
        ]);

        $initialVehicleId = $trip->vehicle_id;

        $this->actingAs($otherSeller)
            ->postJson("/seller/trips/{$trip->id}/assign-vehicle", [
                'vehicle_id' => $vehicle->id,
                'reason' => 'Test',
            ])
            ->assertForbidden();

        // Le véhicule n'a pas été affecté.
        $this->assertSame($initialVehicleId, $trip->fresh()->vehicle_id);
    }

    public function test_seller_of_another_station_cannot_defer_vehicle_assignment(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makePlannedTrip($a, $b);

        $otherSeller = $this->makeUser('seller');
        UserStationAssignment::create([
            'user_id' => $otherSeller->id,
            'station_id' => $c->id,
            'active' => true,
        ]);

        $this->actingAs($otherSeller)
            ->postJson("/seller/trips/{$trip->id}/defer-vehicle-assignment", [
                'reason' => 'Test',
            ])
            ->assertForbidden();

        // sales_ready n'a pas été modifié.
        $this->assertFalse($trip->fresh()->sales_ready);
    }

    // =============================================================
    // I. Affectation d'un retour sans car réel (politique autorisée)
    // =============================================================

    private function makeOpenReturnTrip(): array
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $routeAB = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Vente type', 'seat_count' => 50, 'active' => true]);

        // Programme aller A → B.
        DepartureSchedule::create([
            'station_id' => $a->id,
            'route_id' => $routeAB->id,
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

        // Programme retour B → A (même type, placeholder).
        $routeBA = $this->makeRoute($b, $a);
        DepartureSchedule::create([
            'station_id' => $b->id,
            'route_id' => $routeBA->id,
            'origin_station_id' => $b->id,
            'destination_station_id' => $a->id,
            'departure_time' => '18:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ]);

        app(MaterializeScheduledTrips::class)->materialize();

        $returnTrip = Trip::where('origin_station_id', $b->id)->where('destination_station_id', $a->id)->firstOrFail();
        $user = $this->makeUser();
        app(AuthorizePlannedCapacitySales::class)->authorize($returnTrip, $user, 'Test');

        $outboundTrip = Trip::where('origin_station_id', $a->id)->where('destination_station_id', $b->id)->firstOrFail();
        $result = app(SellRoundTripTicket::class)->sell([
            'trip' => $outboundTrip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => null,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Client',
            'passenger_phone' => '+225****0001',
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);

        return [$returnTrip, $outboundTrip, $result['return'], $user, $a, $b];
    }

    public function test_return_can_be_assigned_without_real_vehicle_when_policy_allows(): void
    {
        [$trip, $outboundTrip, $return, $user, $a, $b] = $this->makeOpenReturnTrip();

        // Le voyage retour (matérialisé) porte un véhicule technique et est
        // ouvert via le report explicite : l'affectation EN QUANTITÉ passe.
        $assigned = app(ReturnJourneyAllocator::class)->assign($return, $trip, null, $user);

        $this->assertSame($trip->id, $assigned->trip_id);
        $this->assertNull($assigned->seat_number, 'Le siège reste nul tant que le car réel n’est pas affecté.');
        $this->assertSame(TicketJourney::SEAT_UNASSIGNED, $assigned->seat_assignment_status);
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $assigned->status);
    }

    public function test_return_seat_requires_real_vehicle_on_placeholder_trip(): void
    {
        [$trip, $outboundTrip, $return, $user, $a, $b] = $this->makeOpenReturnTrip();

        try {
            app(ReturnJourneyAllocator::class)->assign($return, $trip, 5, $user);
            $this->fail('Un siège précis ne peut pas être attribué sans car réel.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('seat_requires_real_vehicle', $e->reasonCode);
        }
    }

    public function test_return_assignment_refused_when_trip_not_sellable(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $routeAB = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Vente type', 'seat_count' => 50, 'active' => true]);

        DepartureSchedule::create([
            'station_id' => $a->id,
            'route_id' => $routeAB->id,
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

        $routeBA = $this->makeRoute($b, $a);
        DepartureSchedule::create([
            'station_id' => $b->id,
            'route_id' => $routeBA->id,
            'origin_station_id' => $b->id,
            'destination_station_id' => $a->id,
            'departure_time' => '18:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ]);

        app(MaterializeScheduledTrips::class)->materialize();

        $returnTrip = Trip::where('origin_station_id', $b->id)->where('destination_station_id', $a->id)->firstOrFail();
        $user = $this->makeUser(); // PAS de report : sales_ready reste false.

        $outboundTrip = Trip::where('origin_station_id', $a->id)->where('destination_station_id', $b->id)->firstOrFail();
        $result = app(SellRoundTripTicket::class)->sell([
            'trip' => $outboundTrip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => null,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Client',
            'passenger_phone' => '+225****0002',
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);

        try {
            app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, null, $user);
            $this->fail('L’affectation doit être refusée quand le voyage n’est pas ouvert à la vente.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('trip_not_sellable', $e->reasonCode);
        }
    }
}
