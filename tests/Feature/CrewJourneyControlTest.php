<?php

namespace Tests\Feature;

use App\Domain\Ticketing\BoardTicketJourney;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\CrewMember;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Models\VehicleType;
use App\Services\ResolveScannedJourney;
use App\Services\ReturnJourneyAllocator;
use App\Services\SellRoundTripTicket;
use App\Services\TicketRefundService;
use App\Services\TripManifestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class CrewJourneyControlTest extends TestCase
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

    private function makeTrip(Station $a, Station $b, string $identifier): Trip
    {
        static $counter = 0;
        $counter++;
        $route = $this->makeRoute($a, $b);
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

    private function makeCrewUser(...$trips): string
    {
        $crew = CrewMember::create([
            'name' => 'Contrôleur Test',
            'phone' => '+2250700000001',
            'role' => 'driver',
            'pin' => Hash::make('1234'),
            'active' => true,
        ]);
        foreach ($trips as $t) {
            VehicleCrewAssignment::create([
                'vehicle_id' => $t->vehicle_id,
                'crew_member_id' => $crew->id,
                'role' => 'driver',
                'assigned_from' => now()->subDay(),
                'active' => true,
            ]);
        }

        return $this->postJson('/api/crew/login', [
            'phone' => $crew->phone,
            'pin' => '1234',
            'device_name' => 'Test',
            'device_id' => 'device-'.uniqid(),
        ])->assertOk()->json('access_token');
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

    private function makeUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    // =============================================================
    // Résolution au scan
    // =============================================================

    public function test_same_qr_resolves_outbound_on_outbound_trip_and_return_on_return_trip(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'CREW-OUT');
        $returnTrip = $this->makeTrip($b, $a, 'CREW-RET');

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $ticket = $result['ticket'];
        $return = $result['return'];
        $user = $this->makeUser();
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, 5, $user);

        $qr = $ticket->qrPayloadString();

        // Sur le voyage aller → droit aller.
        $out = app(ResolveScannedJourney::class)->resolve($qr, $outboundTrip);
        $this->assertSame(ResolveScannedJourney::OUTBOUND_VALID, $out['code']);
        $this->assertSame($ticket->id, $out['ticket']->id);
        $this->assertSame(TicketJourney::DIRECTION_OUTBOUND, $out['journey']->direction);

        // Sur le voyage retour → droit retour.
        $ret = app(ResolveScannedJourney::class)->resolve($qr, $returnTrip);
        $this->assertSame(ResolveScannedJourney::RETURN_VALID, $ret['code']);
        $this->assertSame(TicketJourney::DIRECTION_RETURN, $ret['journey']->direction);
    }

    public function test_scan_return_not_yet_mobilized(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'CREW-OUT2');

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $qr = $result['ticket']->qrPayloadString();

        // Le retour n'est pas mobilisé : scan sur un voyage retour quelconque.
        $someTrip = $this->makeTrip($b, $a, 'CREW-RET2');
        $res = app(ResolveScannedJourney::class)->resolve($qr, $someTrip);

        $this->assertSame(ResolveScannedJourney::RETURN_NOT_MOBILIZED, $res['code']);
        $this->assertSame(TicketJourney::STATUS_PENDING, $res['journey']->status);
    }

    public function test_scan_unknown_ticket_returns_not_found(): void
    {
        $res = app(ResolveScannedJourney::class)->resolve('TIKETI2|INEXISTANT');
        $this->assertSame(ResolveScannedJourney::TICKET_NOT_FOUND, $res['code']);
        $this->assertNull($res['ticket']);
    }

    public function test_manual_search_by_ticket_number(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CREW-OUT3');
        $result = $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 2]);

        $res = app(ResolveScannedJourney::class)->resolveByTicketNumber($result['ticket']->ticket_number, $trip);

        $this->assertSame(ResolveScannedJourney::OUTBOUND_VALID, $res['code']);
    }

    // =============================================================
    // Embarquement par droit
    // =============================================================

    public function test_boarding_outbound_does_not_consume_return(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'CREW-OUT4');
        $returnTrip = $this->makeTrip($b, $a, 'CREW-RET4');

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $return = $result['return'];
        $this->makeUser();
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, 5, $this->makeUser());

        // Embarquement de l'aller.
        $actor = CrewMember::create(['name' => 'C', 'phone' => '+2250700000001', 'role' => 'driver', 'active' => true]);
        app(BoardTicketJourney::class)->execute($actor, $outboundTrip, $result['outbound']);

        $outbound = $result['outbound']->fresh();
        $this->assertSame(TicketJourney::STATUS_BOARDED, $outbound->status);
        $this->assertNotNull($outbound->boarded_at);

        // Le retour n'est PAS consommé.
        $return->refresh();
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $return->status);
        $this->assertNull($return->boarded_at);
    }

    public function test_second_boarding_of_same_journey_is_rejected(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CREW-OUT5');
        $result = $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 3]);

        $actor = CrewMember::create(['name' => 'C2', 'phone' => '+2250700000002', 'role' => 'driver', 'active' => true]);
        app(BoardTicketJourney::class)->execute($actor, $trip, $result['outbound']);

        try {
            app(BoardTicketJourney::class)->execute($actor, $trip, $result['outbound']);
            $this->fail('Le second embarquement du même droit doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('already_boarded', $e->reasonCode);
        }
    }

    public function test_boarding_rejects_journey_assigned_to_another_trip(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CREW-OUT6');
        $otherTrip = $this->makeTrip($a, $b, 'CREW-OUT6B');
        $result = $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 4]);

        $actor = CrewMember::create(['name' => 'C3', 'phone' => '+2250700000003', 'role' => 'driver', 'active' => true]);

        try {
            app(BoardTicketJourney::class)->execute($actor, $otherTrip, $result['outbound']);
            $this->fail('L’embarquement sur un autre voyage doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('wrong_trip', $e->reasonCode);
        }
    }

    // =============================================================
    // HTTP : scan + embarquement par droit
    // =============================================================

    public function test_http_scan_and_board_return_journey(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'CREW-HOUT');
        $returnTrip = $this->makeTrip($b, $a, 'CREW-HRET');
        $token = $this->makeCrewUser($outboundTrip, $returnTrip);

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $return = $result['return'];
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, 8, $this->makeUser());
        $qr = $result['ticket']->qrPayloadString();

        // Scan du QR sur le voyage retour → retour valide.
        $scan = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/crew/tickets/scan', [
                'qr_payload' => $qr,
                'trip_id' => $returnTrip->id,
            ])->assertOk()->json();

        $this->assertTrue($scan['valid']);
        $this->assertSame(ResolveScannedJourney::RETURN_VALID, $scan['code']);
        $this->assertSame($return->id, $scan['journey']['id']);
        $this->assertSame(TicketJourney::DIRECTION_RETURN, $scan['journey']['direction']);

        // Embarquement par droit.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/crew/trips/{$returnTrip->id}/journeys/{$return->id}/board")
            ->assertOk()
            ->assertJsonPath('code', 'boarded')
            ->assertJsonPath('journey.status', TicketJourney::STATUS_BOARDED);

        // Second scan → déjà embarqué.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/crew/tickets/scan', [
                'qr_payload' => $qr,
                'trip_id' => $returnTrip->id,
            ])->assertOk()
            ->assertJsonPath('code', ResolveScannedJourney::ALREADY_BOARDED)
            ->assertJsonPath('valid', false);
    }

    public function test_http_tickets_returns_manifest_and_seat_assignment_version(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CREW-HM');
        $token = $this->makeCrewUser($trip);
        $trip->update(['seat_assignment_version' => 3]);

        $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/crew/trips/{$trip->id}/tickets")
            ->assertOk()
            ->assertJsonPath('seat_assignment_version', 3)
            ->assertJsonCount(1, 'manifest')
            ->assertJsonPath('offline_cache.schema_version', 3)
            ->assertJsonPath('offline_cache.seat_assignment_version', 3)
            ->assertJsonPath('offline_cache.tickets.0.ticket_journey_id', $trip->tickets()->first()->outboundJourney->id);
    }

    // =============================================================
    // H. Résolution : tous les états métier du scan
    // =============================================================

    private function makeResolveFixture(): array
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CREW-RESOLVE');
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        $result = $this->sellRoundTrip($trip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);

        return [$result['ticket'], $result['return'], $trip, $user];
    }

    public function test_resolve_reports_expired_return(): void
    {
        [$ticket, $return, $trip, $user] = $this->makeResolveFixture();

        // Voyage RETOUR B → A sur lequel le client présente son QR.
        $returnTrip = $this->makeTrip($this->makeStation('Gare B2', 'B2'), $this->makeStation('Gare A2', 'A2'), 'CREW-RET-TRIP');
        $return->update([
            'status' => TicketJourney::STATUS_EXPIRED,
            'valid_until' => CarbonImmutable::now()->subDay(),
        ]);

        $resolved = app(ResolveScannedJourney::class)->resolve($ticket->qrPayloadString(), $returnTrip);

        $this->assertSame(ResolveScannedJourney::RETURN_EXPIRED, $resolved['code']);
    }

    public function test_resolve_reports_refunded_return(): void
    {
        [$ticket, $return, $trip, $user] = $this->makeResolveFixture();

        // Remboursement partiel du retour (écriture compensatoire).
        app(TicketRefundService::class)->refundReturn($ticket, $user, 'Test');

        // Voyage RETOUR B → A sur lequel le client présente son QR.
        $returnTrip = $this->makeTrip($this->makeStation('Gare R2', 'R2'), $this->makeStation('Gare R3', 'R3'), 'CREW-RET-TRIP2');
        $resolved = app(ResolveScannedJourney::class)->resolve($ticket->qrPayloadString(), $returnTrip);

        $this->assertSame(ResolveScannedJourney::RETURN_REFUNDED, $resolved['code']);
    }

    public function test_resolve_reports_completed_journey(): void
    {
        [$ticket, $return, $trip, $user] = $this->makeResolveFixture();
        $ticket->outboundJourney->update(['status' => TicketJourney::STATUS_COMPLETED]);

        $resolved = app(ResolveScannedJourney::class)->resolve($ticket->qrPayloadString(), $trip);

        $this->assertSame(ResolveScannedJourney::JOURNEY_COMPLETED, $resolved['code']);
    }

    public function test_resolve_reports_cancelled_ticket(): void
    {
        [$ticket, $return, $trip, $user] = $this->makeResolveFixture();
        $ticket->update(['status' => 'cancelled']);

        $resolved = app(ResolveScannedJourney::class)->resolve($ticket->qrPayloadString(), $trip);

        $this->assertSame(ResolveScannedJourney::TICKET_CANCELLED, $resolved['code']);
    }

    public function test_resolve_outbound_scan_does_not_consume_return(): void
    {
        [$ticket, $return, $trip, $user] = $this->makeResolveFixture();

        // Embarque l'aller via le droit (acteur CrewMember).
        $crew = CrewMember::create([
            'name' => 'Contrôleur H',
            'phone' => '+2250700000008',
            'role' => 'driver',
            'active' => true,
        ]);
        app(BoardTicketJourney::class)->execute(
            $crew,
            $trip,
            $ticket->outboundJourney,
        );

        // Le retour reste utilisable.
        $this->assertNotSame(TicketJourney::STATUS_BOARDED, $return->fresh()->status);
        $this->assertSame(TicketJourney::STATUS_PENDING, $return->fresh()->status);
    }

    // =============================================================
    // B. Manifeste : un retour apparaît dans le manifeste du voyage retour
    // =============================================================

    public function test_return_appears_in_return_trip_manifest_even_if_ticket_points_to_outbound(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'CREW-MAN-OUT');
        $returnTrip = $this->makeTrip($b, $a, 'CREW-MAN-RET');
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $assignedReturn = app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, null, $user);

        // Le billet racine reste associé à l'ALLER (tickets.trip_id = aller),
        // mais le droit retour est affecté au voyage RETOUR.
        $this->assertSame($outboundTrip->id, $result['ticket']->trip_id);
        $this->assertSame($returnTrip->id, $assignedReturn->trip_id);

        // Manifeste du voyage RETOUR : le droit retour y figure.
        $manifest = app(TripManifestService::class)->forTrip($returnTrip);
        $this->assertCount(1, $manifest);
        $this->assertSame(TicketJourney::DIRECTION_RETURN, $manifest->first()['direction']);
        $this->assertSame($assignedReturn->id, $manifest->first()['journey_id']);
        $this->assertSame($result['ticket']->id, $manifest->first()['ticket_id']);
    }

    public function test_return_trip_cache_includes_round_trip_ticket(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outboundTrip = $this->makeTrip($a, $b, 'CREW-CACHE-OUT');
        $returnTrip = $this->makeTrip($b, $a, 'CREW-CACHE-RET');
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);

        $result = $this->sellRoundTrip($outboundTrip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, null, $user);

        // Le cache du voyage RETOUR doit contenir le billet (via son droit
        // retour), même si le billet racine pointe vers l'aller.
        $token = $this->makeCrewUser($outboundTrip, $returnTrip);
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/crew/trips/{$returnTrip->id}/tickets")
            ->assertOk();

        $cacheTickets = $response->json('offline_cache.tickets');
        $this->assertSame(3, $response->json('offline_cache.schema_version'));

        $journeyTicket = collect($cacheTickets)->first(
            fn (array $t) => ($t['ticket_journey_id'] ?? null) === $result['return']->id
        );
        $this->assertNotNull($journeyTicket, 'Le billet aller-retour doit figurer dans le cache du voyage retour.');
        $this->assertSame('return', $journeyTicket['journey_direction']);
    }

    // =============================================================
    // A. Synchronisation hors ligne par ticket_journey_id
    // =============================================================

    public function test_offline_sync_boards_by_ticket_journey_id(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CREW-SYNC');
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);

        $result = $this->sellRoundTrip($trip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $journey = $result['return'];

        // Le retour doit être affecté au voyage avant l'embarquement.
        $returnTrip = $this->makeTrip($b, $a, 'CREW-SYNC-RET');
        $user2 = User::factory()->create(['role' => 'admin', 'active' => true]);
        app(ReturnJourneyAllocator::class)->assign($journey, $returnTrip, null, $user2);

        // Le crew doit être autorisé sur le véhicule du voyage retour.
        $token = $this->makeCrewUser($trip, $returnTrip);

        // Embarquement hors ligne par droit (pas par ticket) — sur le voyage RETOUR.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$returnTrip->id}/tickets/sync", [
                'boardings' => [[
                    'client_action_id' => (string) Str::uuid(),
                    'ticket_journey_id' => $journey->id,
                    'boarded_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'results.boarded')
            ->assertJsonPath('results.boarded.0.ticket_journey_id', $journey->id);

        $this->assertSame(TicketJourney::STATUS_BOARDED, $journey->fresh()->status);
    }
}
