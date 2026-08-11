<?php

namespace Tests\Feature;

use App\Models\OkohiTicketOutbox;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

/**
 * Point 5 : identification Okohi — la validation repose sur la VÉRIFICATION
 * serveur auprès d'Okohi (numéro canonique), pas sur une simple regex.
 */
class OkohiCustomerIdentificationTest extends TestCase
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

        config()->set('services.okohi.base_url', 'https://okohi.test');
        config()->set('services.okohi.integration_key', 'test-key');
        config()->set('services.okohi.secret', 'test-secret');
        TicketSetting::getSettings()->update([
            'okohi_integration_url' => 'https://okohi.test/scan/{ticket_id}',
            'okohi_integration_key' => 'integration-secret-key',
        ]);
    }

    /** Simule Okohi : le client OKH-123456 existe (numéro canonique en majuscules). */
    private function fakeCustomerFound(string $number): void
    {
        Http::fake([
            'https://okohi.test/api/v1/partner/customers/*' => Http::response([
                'success' => true,
                'customer' => ['customer_number' => strtoupper($number)],
            ], 200),
        ]);
    }

    /** Simule Okohi : le client n'existe pas. */
    private function fakeCustomerNotFound(): void
    {
        Http::fake([
            'https://okohi.test/api/v1/partner/customers/*' => Http::response(['success' => false], 404),
        ]);
    }

    private function makeStation(string $name, string $code): Station
    {
        return Station::create(['name' => $name, 'code' => $code, 'city' => $name, 'active' => true]);
    }

    private function makeTrip(Station $a, Station $b, string $identifier): Trip
    {
        static $counter = 0;
        $counter++;
        $route = Route::create(['origin_station_id' => $a->id, 'destination_station_id' => $b->id, 'name' => "{$a->name}→{$b->name}", 'active' => true]);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Type '.$counter, 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => $identifier, 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

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

    private function httpSale(Trip $trip, Station $a, Station $b, array $overrides = []): TestResponse
    {
        $seller = User::factory()->create(['role' => 'admin', 'active' => true]);

        return $this->actingAs($seller)->postJson('/seller/tickets', array_merge([
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seats' => [1],
            'journey_type' => 'one_way',
            'passenger_name' => 'Client Identifié',
            'passenger_phone' => '2250700000001',
            'amount' => 3000,
        ], $overrides));
    }

    public function test_sale_with_verified_okohi_customer_stores_canonical_number(): void
    {
        $this->fakeCustomerFound('OKH-123456');

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKH-OWNER');

        // Le navigateur envoie une variante en minuscules : le serveur stocke
        // le numéro CANONIQUE retourné par Okohi, jamais la valeur brute.
        $response = $this->httpSale($trip, $a, $b, ['okohi_customer_number' => 'okh-123456']);
        $response->assertCreated();

        $ticket = Ticket::find($response->json('ticket_ids.0'));
        $this->assertSame('OKH-123456', $ticket->okohi_customer_number);

        // L'outbox contient l'identité vérifiée du client.
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('OKH-123456', $outbox->payload['customer']['okohi_customer_number']);
    }

    public function test_sale_without_identified_customer_keeps_null_and_creates_no_orphan_outbox(): void
    {
        $this->fakeCustomerFound('OKH-123456');

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKH-ANON');

        $this->httpSale($trip, $a, $b)->assertCreated();

        $ticket = Ticket::latest('id')->first();
        $this->assertNull($ticket->okohi_customer_number);

        // Point 5 : pas de wallet ticket orphelin — le billet anonyme n'est
        // pas publié vers Okohi (okohi_delivery_status = not_requested).
        $this->assertSame('not_requested', $ticket->okohi_delivery_status);
        $this->assertSame(0, OkohiTicketOutbox::count());
    }

    public function test_invalid_format_is_rejected(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKH-INVALID');

        // Identifiant arbitraire fourni par le navigateur → refusé (422).
        $this->httpSale($trip, $a, $b, ['okohi_customer_number' => "'; DROP TABLE tickets;--"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('okohi_customer_number');

        $this->assertSame(0, Ticket::count(), 'Aucun billet ne doit être créé avec une identité invalide.');
    }

    public function test_format_valid_but_unknown_customer_is_rejected(): void
    {
        // Format correct mais client inexistant chez Okohi → erreur métier 422.
        $this->fakeCustomerNotFound();

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKH-UNKNOWN');

        $this->httpSale($trip, $a, $b, ['okohi_customer_number' => 'OKH-999999'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'okohi_customer_not_found');

        $this->assertSame(0, Ticket::count(), 'Aucun billet ne doit être créé avec un client inexistant.');
    }

    public function test_okohi_outage_does_not_block_sale_but_never_attaches_unverified_wallet(): void
    {
        // Panne Okohi (503) : la vente passe, mais le billet n'est PAS rattaché
        // arbitrairement à un portefeuille non vérifié.
        Http::fake(fn () => Http::response('', 503));

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKH-OUTAGE');

        $response = $this->httpSale($trip, $a, $b, ['okohi_customer_number' => 'OKH-654321']);
        $response->assertCreated();

        $ticket = Ticket::find($response->json('ticket_ids.0'));
        $this->assertNull($ticket->okohi_customer_number, 'Panne Okohi : jamais de rattachement non vérifié.');
        $this->assertSame('not_requested', $ticket->okohi_delivery_status);
        $this->assertSame(0, OkohiTicketOutbox::count());
    }

    public function test_round_trip_sale_with_identified_customer(): void
    {
        $this->fakeCustomerFound('OKH-111222');

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKH-RT');
        $this->setRoundTripDiscount(500);

        $seller = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($seller)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'seats' => [1],
            'journey_type' => 'round_trip',
            'return_mode' => 'open',
            'passenger_name' => 'Client AR',
            'passenger_phone' => '2250700000002',
            'amount' => 5500,
            'okohi_customer_number' => 'OKH-111222',
        ])->assertCreated();

        $ticket = Ticket::latest('id')->first();
        $this->assertSame('round_trip', $ticket->journey_type);
        $this->assertSame('OKH-111222', $ticket->okohi_customer_number);

        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();
        $this->assertSame('OKH-111222', $outbox->payload['customer']['okohi_customer_number']);
        $this->assertSame('round_trip', $outbox->payload['journey_type']);
    }
}
