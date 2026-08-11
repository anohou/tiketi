<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\RoundTripFareCalculator;
use App\Services\TicketReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class RoundTripFareTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithTenantTicketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantTicketingTablesExist();
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

    // =============================================================
    // Calculateur tarifaire
    // =============================================================

    public function test_calculator_uses_normal_fares_without_round_trip_offer(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');

        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);

        $fare = app(RoundTripFareCalculator::class)->calculate($a->id, $b->id);

        $this->assertSame(3000, $fare['outbound_amount']);
        $this->assertSame(3000, $fare['return_amount']);
        $this->assertSame(6000, $fare['normal_total']);
        $this->assertSame(6000, $fare['round_trip_amount']);
        $this->assertSame(0, $fare['discount']);
        $this->assertSame(6000, $fare['amount_to_collect']);
    }

    public function test_calculator_applies_round_trip_offer_and_historizes_discount(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');

        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $this->setRoundTripDiscount(500);

        $fare = app(RoundTripFareCalculator::class)->calculate($a->id, $b->id);

        // Test d'acceptation du plan : 3 000 + 3 000 avec remise globale 500.
        $this->assertSame(5500, $fare['amount_to_collect']);
        $this->assertSame(500, $fare['discount']);
        $this->assertSame(6000, $fare['normal_total']);
        $this->assertSame(5500, $fare['round_trip_amount']);
    }

    public function test_calculator_supports_reverse_pair_symmetry(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');

        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 4000, 'is_bidirectional' => true, 'active' => true]);
        $this->setRoundTripDiscount(1000);

        // Le calcul B→A applique la même remise globale.
        $fare = app(RoundTripFareCalculator::class)->calculate($b->id, $a->id);

        $this->assertSame(7000, $fare['amount_to_collect']);
        $this->assertSame(1000, $fare['discount']);
    }

    public function test_calculator_rejects_unknown_pair(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');

        $this->expectException(\RuntimeException::class);
        app(RoundTripFareCalculator::class)->calculate($a->id, $b->id);
    }

    public function test_selection_mode_allows_all_modes_with_global_discount(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');

        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $this->setRoundTripDiscount(500);

        $calculator = app(RoundTripFareCalculator::class);
        $fare = $calculator->calculate($a->id, $b->id);

        $this->assertTrue($calculator->validateSelectionMode($fare, TicketJourney::SELECTION_FIXED_SCHEDULE));
        $this->assertTrue($calculator->validateSelectionMode($fare, TicketJourney::SELECTION_DATE_FLEXIBLE));
        $this->assertTrue($calculator->validateSelectionMode($fare, TicketJourney::SELECTION_OPEN));
    }

    // =============================================================
    // QR / jeton public
    // =============================================================

    public function test_ticket_gets_opaque_public_token_on_creation(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        $type = VehicleType::create(['name' => 'Bus 50', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-01', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay(),
            'status' => 'scheduled',
            'operational_ready' => true,
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $ticket = Ticket::create([
            'ticket_number' => 'TKT-TOKEN-001',
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seat_number' => 1,
            'price' => 3000,
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'status' => 'issued',
        ]);

        $this->assertNotNull($ticket->public_token);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $ticket->public_token);
        $this->assertSame('TIKETI2|'.$ticket->public_token, $ticket->qrPayloadString());
    }

    public function test_qr_resolution_supports_new_and_legacy_formats(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        $type = VehicleType::create(['name' => 'Bus 50', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-02', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay(),
            'status' => 'scheduled',
            'operational_ready' => true,
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $ticket = Ticket::create([
            'ticket_number' => 'TKT-LEGACY-01',
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seat_number' => 2,
            'price' => 3000,
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'status' => 'issued',
        ]);

        // Nouveau format.
        $this->assertSame($ticket->id, Ticket::resolveFromQrValue($ticket->qrPayloadString())?->id);

        // Ancien format TIKETI|n°|id.
        $legacyQr = 'TIKETI|'.$ticket->ticket_number.'|'.$ticket->id;
        $this->assertSame($ticket->id, Ticket::resolveFromQrValue($legacyQr)?->id);

        // ID brut.
        $this->assertSame($ticket->id, Ticket::resolveFromQrValue($ticket->id)?->id);

        // Inconnu.
        $this->assertNull(Ticket::resolveFromQrValue('TIKETI2|INCONNU'));
    }

    // =============================================================
    // Backfill
    // =============================================================

    public function test_backfill_creates_outbound_journey_for_existing_tickets(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        $type = VehicleType::create(['name' => 'Bus 50', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-03', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay(),
            'status' => 'scheduled',
            'operational_ready' => true,
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        // Billet « ancien » sans jeton ni droit (simule l'avant-migration).
        $ticket = Ticket::create([
            'ticket_number' => 'TKT-BACKFILL-01',
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seat_number' => 5,
            'price' => 3000,
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'status' => 'issued',
        ]);
        $ticket->forceFill(['public_token' => null])->save();
        $ticket->outboundJourney()?->delete();

        Artisan::call('tickets:backfill-journeys', ['--dry-run' => true]);
        $this->assertSame(0, TicketJourney::count(), 'Le dry-run ne doit rien écrire.');

        Artisan::call('tickets:backfill-journeys');

        $ticket->refresh();
        $this->assertNotNull($ticket->public_token);
        $this->assertNotNull($ticket->outboundJourney);

        $journey = $ticket->outboundJourney;
        $this->assertSame(TicketJourney::DIRECTION_OUTBOUND, $journey->direction);
        $this->assertSame(TicketJourney::SELECTION_FIXED_TRIP, $journey->selection_mode);
        $this->assertSame($trip->id, $journey->trip_id);
        $this->assertSame(5, $journey->seat_number);
        $this->assertSame(TicketJourney::SEAT_CONFIRMED, $journey->seat_assignment_status);
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $journey->status);
    }

    public function test_backfill_is_idempotent(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        $type = VehicleType::create(['name' => 'Bus 50', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-04', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay(),
            'status' => 'scheduled',
            'operational_ready' => true,
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        Ticket::create([
            'ticket_number' => 'TKT-IDEMP-01',
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seat_number' => 7,
            'price' => 3000,
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'status' => 'issued',
        ]);

        Artisan::call('tickets:backfill-journeys');
        Artisan::call('tickets:backfill-journeys');

        $this->assertSame(1, TicketJourney::count(), 'Deux exécutions ne créent qu’un droit par billet.');
    }

    // =============================================================
    // Rapprochement
    // =============================================================

    public function test_reconciliation_detects_missing_outbound_journeys(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $route = $this->makeRoute($a, $b);
        $type = VehicleType::create(['name' => 'Bus 50', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-05', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $a->id,
            'destination_station_id' => $b->id,
            'departure_at' => CarbonImmutable::now()->addDay(),
            'status' => 'scheduled',
            'operational_ready' => true,
        ]);
        $user = \App\Models\User::factory()->create(['role' => 'admin', 'active' => true]);

        $ticket = Ticket::create([
            'ticket_number' => 'TKT-RECON-01',
            'trip_id' => $trip->id,
            'vehicle_id' => $vehicle->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seat_number' => 9,
            'price' => 3000,
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'status' => 'issued',
        ]);

        // Sans droit : anomalie détectée.
        $report = app(TicketReconciliationService::class)->reconcile();
        $this->assertSame(1, $report['tickets_without_outbound']);
        $this->assertNotEmpty($report['anomalies']);

        // Après backfill : plus d'anomalie.
        Artisan::call('tickets:backfill-journeys');

        $report = app(TicketReconciliationService::class)->reconcile();
        $this->assertSame(0, $report['tickets_without_outbound']);
        $this->assertSame(1, $report['journeys_outbound']);
        $this->assertEmpty($report['anomalies']);
    }
}
