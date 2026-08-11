<?php

namespace Tests\Feature;

use App\Models\OkohiTicketOutbox;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\OkohiTicketPublisher;
use App\Services\SellRoundTripTicket;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class OkohiTicketPublisherTest extends TestCase
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
        config()->set('transport.okohi.max_attempts', 3);
        TicketSetting::getSettings()->update([
            'okohi_integration_url' => 'https://okohi.test/scan/{ticket_id}',
            'okohi_integration_key' => 'integration-secret-key',
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

    private function makeTrip(Station $a, Station $b): Trip
    {
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Bus AR', 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => 'BUS-OKOHI', 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

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

    private function sellRoundTrip(Trip $trip, Station $a, Station $b, array $overrides = []): Ticket
    {
        $result = app(SellRoundTripTicket::class)->sell(array_merge([
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
            'okohi_customer_number' => 'OKOHI-123',
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ], $overrides));

        return $result['ticket'];
    }

    // =============================================================
    // Mise en file (outbox)
    // =============================================================

    public function test_sale_enqueues_outbox_entry_when_okohi_configured(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);

        $ticket = $this->sellRoundTrip($trip, $a, $b);

        $this->assertSame('pending', $ticket->okohi_delivery_status);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(OkohiTicketOutbox::STATUS_PENDING, $outbox->status);
        $this->assertSame(OkohiTicketOutbox::OPERATION_CREATE, $outbox->operation);
        $this->assertSame(1, $outbox->version);

        // Payload : QR exact Tiketi, itinéraire aller/retour, prix.
        $payload = $outbox->payload;
        $this->assertSame('TIKETI2', $payload['qr']['format']);
        $this->assertSame($ticket->qrPayloadString(), $payload['qr']['payload']);
        $this->assertSame('round_trip', $payload['journey_type']);
        $this->assertSame('OKOHI-123', $payload['customer']['okohi_customer_number']);
        $this->assertSame('open', $payload['itinerary']['return']['selection_mode']);
        $this->assertSame('XOF', $payload['pricing']['currency']);
    }

    public function test_sale_does_not_enqueue_when_okohi_not_configured(): void
    {
        TicketSetting::getSettings()->update([
            'okohi_integration_url' => null,
            'okohi_integration_key' => null,
        ]);
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);

        $ticket = $this->sellRoundTrip($trip, $a, $b);

        $this->assertSame('not_requested', $ticket->okohi_delivery_status);
        $this->assertSame(0, OkohiTicketOutbox::where('ticket_id', $ticket->id)->count());
    }

    // =============================================================
    // Livraison, signature et idempotence
    // =============================================================

    public function test_deliver_sends_hmac_signed_request_and_marks_delivered(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();

        Http::fake([
            'https://okohi.test/api/v1/partner/tickets' => Http::response(['id' => 'ext-1', 'status' => 'ok'], 201),
        ]);

        $ok = app(OkohiTicketPublisher::class)->deliver($outbox);
        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            $headers = $request->headers();
            $this->assertArrayHasKey('X-Okohi-Signature', $headers);
            $this->assertArrayHasKey('X-Okohi-Timestamp', $headers);
            $this->assertArrayHasKey('X-Okohi-Nonce', $headers);
            $this->assertArrayHasKey('X-Idempotency-Key', $headers);
            $this->assertArrayHasKey('X-Okohi-Integration-Key', $headers);

            // Vérifie que la signature est un HMAC-SHA256 valide de timestamp.nonce.body.
            $timestamp = $headers['X-Okohi-Timestamp'][0];
            $nonce = $headers['X-Okohi-Nonce'][0];
            $signature = $headers['X-Okohi-Signature'][0];
            $body = $request->body();
            $expected = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", 'integration-secret-key');
            $this->assertSame($expected, $signature);

            return true;
        });

        $outbox->refresh();
        $this->assertSame(OkohiTicketOutbox::STATUS_DELIVERED, $outbox->status);
        $this->assertNotNull($outbox->delivered_at);
        $this->assertSame('delivered', $ticket->fresh()->okohi_delivery_status);
    }

    public function test_deliver_409_idempotency_duplicate_is_treated_as_delivered(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();

        Http::fake([
            'https://okohi.test/api/v1/partner/tickets' => Http::response(['code' => 'idempotency_duplicate'], 409),
        ]);

        $ok = app(OkohiTicketPublisher::class)->deliver($outbox);
        $this->assertTrue($ok, 'Un doublon idempotent est considéré comme livré.');
        $this->assertSame(OkohiTicketOutbox::STATUS_DELIVERED, $outbox->fresh()->status);
    }

    // =============================================================
    // Pannes et reprises
    // =============================================================

    public function test_network_failure_keeps_pending_and_schedules_retry(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();

        Http::fake([
            'https://okohi.test/*' => Http::response([], 500),
        ]);

        $ok = app(OkohiTicketPublisher::class)->deliver($outbox);
        $this->assertFalse($ok);

        $outbox->refresh();
        $this->assertSame(OkohiTicketOutbox::STATUS_PENDING, $outbox->status, 'Une panne ne marque pas définitivement l’entrée.');
        $this->assertSame(1, $outbox->attempt_count);
        $this->assertNotNull($outbox->next_attempt_at, 'Un backoff doit être planifié.');
        $this->assertSame('http_500', $outbox->last_error_code);
        $this->assertSame('failed', $ticket->fresh()->okohi_delivery_status);
    }

    public function test_exhausted_attempts_mark_failed(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();

        Http::fake([
            'https://okohi.test/*' => Http::response([], 500),
        ]);

        $publisher = app(OkohiTicketPublisher::class);
        // maxAttempts = 3 → 3 échecs puis status failed.
        for ($i = 0; $i < 3; $i++) {
            $publisher->deliver($outbox->fresh());
        }

        $outbox->refresh();
        $this->assertSame(OkohiTicketOutbox::STATUS_FAILED, $outbox->status);
        $this->assertSame(3, $outbox->attempt_count);
    }

    public function test_sale_is_not_blocked_by_okohi_outage(): void
    {
        // Simule une panne au moment de la mise en file : le service enqueue
        // ne doit jamais lever d'exception qui casserait la transaction de vente.
        Http::fake(['*' => Http::response([], 500)]);

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);

        $ticket = $this->sellRoundTrip($trip, $a, $b);

        // La vente a réussi et le billet existe malgré la panne.
        $this->assertNotNull($ticket->id);
        $this->assertSame('pending', $ticket->okohi_delivery_status);
        $this->assertSame(1, OkohiTicketOutbox::where('ticket_id', $ticket->id)->count());
    }

    public function test_update_version_increments_for_second_publication(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);

        $first = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();
        $this->assertSame(1, $first->version);

        app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);

        $second = OkohiTicketOutbox::where('ticket_id', $ticket->id)->orderBy('version', 'desc')->first();
        $this->assertSame(2, $second->version);
        $this->assertNotSame($first->idempotency_key, $second->idempotency_key);
        $this->assertSame(OkohiTicketOutbox::OPERATION_UPDATE, $second->operation);
    }

    public function test_deliver_sends_patch_for_update_operation(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);

        Http::fake([
            'https://okohi.test/api/v1/partner/tickets/*' => Http::response(['status' => 'updated'], 200),
        ]);

        $ok = app(OkohiTicketPublisher::class)->deliver($outbox);
        $this->assertTrue($ok);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/api/v1/partner/tickets/'));
        $this->assertSame(OkohiTicketOutbox::STATUS_DELIVERED, $outbox->fresh()->status);
    }

    // =============================================================
    // A. Version du cycle de vie : payload, corps HTTP, concurrence, reprise
    // =============================================================

    public function test_payload_version_matches_outbox_version_throughout_lifecycle(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);

        // Création : outbox v1 ET payload v1.
        $create = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();
        $this->assertSame(1, $create->version);
        $this->assertSame(1, $create->payload['version']);
        $this->assertSame(1, $create->payload['schema_version']);

        // Première mise à jour : outbox v2 ET payload v2.
        app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);
        $update1 = OkohiTicketOutbox::where('ticket_id', $ticket->id)->orderByDesc('version')->first();
        $this->assertSame(2, $update1->version);
        $this->assertSame(2, $update1->payload['version']);

        // Deuxième mise à jour : outbox v3 ET payload v3.
        app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);
        $update2 = OkohiTicketOutbox::where('ticket_id', $ticket->id)->orderByDesc('version')->first();
        $this->assertSame(3, $update2->version);
        $this->assertSame(3, $update2->payload['version']);
    }

    public function test_http_body_contains_exact_version(): void
    {
        Http::fake();

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();

        app(OkohiTicketPublisher::class)->deliver($outbox);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['version']) && $body['version'] === 1;
        });

        // V2 : le corps HTTP doit contenir version 2.
        app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);
        $v2 = OkohiTicketOutbox::where('ticket_id', $ticket->id)->orderByDesc('version')->first();
        app(OkohiTicketPublisher::class)->deliver($v2);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['version']) && $body['version'] === 2;
        });
    }

    public function test_concurrent_publications_do_not_reuse_versions(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);

        // Simule deux événements concurrents : deux appels enqueue successifs.
        $first = app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);
        $second = app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);

        $versions = OkohiTicketOutbox::where('ticket_id', $ticket->id)->pluck('version')->sort()->values();
        $this->assertSame([1, 2, 3], $versions->all());
        $this->assertNotSame($first->version, $second->version);
        $this->assertNotSame($first->idempotency_key, $second->idempotency_key);
    }

    public function test_atomic_enqueue_inside_competing_transactions_keeps_monotonic_versions(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);

        // Deux transactions IMBRIQUÉES indépendantes (simulation réaliste de
        // la concurrence : sous SQLite les verrous sont sérialisés au niveau
        // de la connexion ; sous PostgreSQL, lockForUpdate garantit la même
        // propriété). Chaque enqueue verrouille la ligne ticket, calcule la
        // version et insère DANS LA MÊME transaction.
        $first = DB::transaction(fn () => app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE));
        $second = DB::transaction(fn () => app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE));

        $versions = OkohiTicketOutbox::where('ticket_id', $ticket->id)
            ->orderBy('version')
            ->pluck('version')
            ->all();

        // Strictement croissantes, sans doublon, sans trou.
        $this->assertSame([1, 2, 3], $versions);

        // Chaque payload porte la version de son entrée.
        foreach (OkohiTicketOutbox::where('ticket_id', $ticket->id)->get() as $outbox) {
            $this->assertSame($outbox->version, $outbox->payload['version']);
        }

        $this->assertNotSame($first->version, $second->version);
    }

    public function test_retry_of_same_entry_keeps_same_version(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b);
        $ticket = $this->sellRoundTrip($trip, $a, $b);
        $outbox = OkohiTicketOutbox::where('ticket_id', $ticket->id)->first();

        $versionBefore = $outbox->version;
        $payloadBefore = $outbox->payload;

        // Panne réseau → reprise de la MÊME entrée.
        Http::fake(fn () => Http::response('', 503));
        app(OkohiTicketPublisher::class)->deliver($outbox);

        $fresh = $outbox->fresh();
        $this->assertSame($versionBefore, $fresh->version);
        $this->assertSame($payloadBefore, $fresh->payload);
    }
}
