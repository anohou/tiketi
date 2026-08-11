<?php

namespace Tests\Feature;

use App\Domain\Ticketing\BoardTicketJourney;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Domain\Trips\TripStateMachine;
use App\Models\DepartureSchedule;
use App\Models\OkohiTicketOutbox;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Models\TicketJourney;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ChangeReturnPreference;
use App\Services\ExtendReturnJourney;
use App\Services\ReleaseTripReturns;
use App\Services\ReturnJourneyAllocator;
use App\Services\ReturnQuotaService;
use App\Services\ResolveScannedJourney;
use App\Services\RoundTripRevenueService;
use App\Services\OkohiTicketPublisher;
use App\Services\TicketCompensationService;
use App\Services\SellRoundTripTicket;
use App\Services\TicketRefundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class FinanceAndLifecycleTest extends TestCase
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

        config()->set('services.okohi.base_url', 'https://okohi.test');
        config()->set('services.okohi.integration_key', 'test-key');
        config()->set('services.okohi.secret', 'test-secret');
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

    private function makeTrip(Station $a, Station $b, string $identifier): Trip
    {
        static $counter = 0;
        $counter++;
        $route = $this->makeRoute($a, $b);
        RouteFare::create(['from_station_id' => $a->id, 'to_station_id' => $b->id, 'amount' => 3000, 'is_bidirectional' => true, 'active' => true]);
        $type = VehicleType::create(['name' => 'Fin '.$counter, 'seat_count' => 50, 'active' => true]);
        $vehicle = Vehicle::create(['identifier' => $identifier, 'vehicle_type_id' => $type->id, 'seat_count' => 50, 'active' => true]);

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

    private function withRoundTripFare(Station $a, Station $b): void
    {
        // Remise globale de 500 FCFA sur le total aller + retour (2×3000).
        $this->setRoundTripDiscount(500);
    }

    // =============================================================
    // Remboursement partiel du retour
    // =============================================================

    public function test_refund_return_creates_compensatory_write_without_overwriting_prices(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT1');
        $this->withRoundTripFare($a, $b);

        $result = $this->sellRoundTrip($trip, $a, $b);
        $ticket = $result['ticket'];
        $user = $this->makeUser();

        // Valeur remboursable du retour : (normal_total - remise) / 2 = (6000-500)/2 = 2750.
        $max = app(TicketRefundService::class)->maxRefundableAmount($ticket);
        $this->assertSame(2750, $max);

        $refund = app(TicketRefundService::class)->refundReturn($ticket, $user, 'Client annule son retour.');

        $this->assertSame(2750, $refund['refunded_amount']);
        $this->assertSame(TicketJourney::STATUS_CANCELLED, $refund['journey']->status);

        // Les prix historiques ne sont JAMAIS écrasés.
        $ticket->refresh();
        $this->assertSame(5500, $ticket->price);
        $this->assertSame(5500, $ticket->amount_collected);
        $this->assertSame(6000, $ticket->normal_total_amount);
        $this->assertSame(500, $ticket->round_trip_discount_amount);

        // L'écriture compensatoire est historisée.
        $comp = TicketCompensation::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($comp);
        $this->assertSame('refund', $comp->compensation_type);
        $this->assertSame('executed', $comp->status);
        $this->assertSame(2750, $comp->amount);
        $this->assertSame('return_only', $comp->settings['scope']);
        $this->assertSame(5500, $comp->settings['original_price']);
        $this->assertSame($comp->reference, data_get($ticket->settings, 'refund.return_reference'));
    }

    public function test_refund_return_rejects_when_return_already_used(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT2');
        $this->withRoundTripFare($a, $b);
        $result = $this->sellRoundTrip($trip, $a, $b);
        $user = $this->makeUser();

        // Marque le retour comme consommé.
        $result['return']->update(['status' => TicketJourney::STATUS_BOARDED]);

        try {
            app(TicketRefundService::class)->refundReturn($result['ticket'], $user, 'Test');
            $this->fail('Un retour consommé ne peut pas être remboursé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('return_already_used', $e->reasonCode);
        }
    }

    public function test_refund_return_rejects_amount_above_return_value(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT3');
        $this->withRoundTripFare($a, $b);
        $result = $this->sellRoundTrip($trip, $a, $b);

        try {
            app(TicketRefundService::class)->refundReturn($result['ticket'], $this->makeUser(), 'Test', 5000);
            $this->fail('Le montant ne peut pas dépasser la valeur du retour.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('refund_exceeds_return_value', $e->reasonCode);
        }
    }

    // =============================================================
    // Voyage annulé → retours dans le pool
    // =============================================================

    public function test_cancelled_trip_releases_returns_to_pool_with_history(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeTrip($a, $b, 'FIN-OUT4');
        $returnTrip = $this->makeTrip($b, $a, 'FIN-RET4');
        $this->withRoundTripFare($a, $b);

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $return = $result['return'];
        $user = $this->makeUser();
        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, 4, $user);
        $this->assertSame(TicketJourney::STATUS_ASSIGNED, $return->fresh()->status);

        // Annulation du voyage retour via la machine d'état.
        app(TripStateMachine::class)->transition($returnTrip, 'cancelled', $user, 'test', 'Car en panne');

        $return->refresh();
        $this->assertNull($return->trip_id, 'Le retour doit être remis dans le pool.');
        $this->assertSame(TicketJourney::STATUS_PENDING, $return->status, 'Retour ouvert → pending dans le pool.');
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $returnTrip->id)->count(), 'La place est libérée.');

        // Historique automatique avec le motif.
        $history = $return->assignments()->where('reason', 'trip_cancelled')->first();
        $this->assertNotNull($history);
        $this->assertSame($returnTrip->id, $history->previous_trip_id);
        $this->assertSame(\App\Models\TicketJourneyAssignment::MODE_AUTOMATIC, $history->mode);
    }

    public function test_release_trip_returns_is_idempotent(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $returnTrip = $this->makeTrip($b, $a, 'FIN-RET5');
        $outbound = $this->makeTrip($a, $b, 'FIN-OUT5');

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $user = $this->makeUser();
        app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, 6, $user);

        $service = app(ReleaseTripReturns::class);
        $this->assertSame(1, $service->release($returnTrip));
        $this->assertSame(0, $service->release($returnTrip), 'Seconde exécution : rien à libérer.');
    }

    // =============================================================
    // Changement de préférence
    // =============================================================

    public function test_change_preference_unassigns_and_updates_atomically(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeTrip($a, $b, 'FIN-OUT6');
        $returnTrip = $this->makeTrip($b, $a, 'FIN-RET6');
        $user = $this->makeUser();

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        app(ReturnJourneyAllocator::class)->assign($result['return'], $returnTrip, 8, $user);

        $changed = app(ChangeReturnPreference::class)->change(
            $result['return'],
            $user,
            ['desired_travel_date' => CarbonImmutable::now()->addDays(10)->toDateString()],
        );

        $this->assertNull($changed->trip_id, 'L’ancienne affectation est retirée.');
        $this->assertSame(CarbonImmutable::now()->addDays(10)->toDateString(), $changed->desired_travel_date->toDateString());
        $this->assertSame(TicketJourney::STATUS_PENDING, $changed->status);
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $returnTrip->id)->count(), 'La place est libérée.');

        // Historique : preference_change.
        $this->assertNotNull($result['return']->assignments()->where('reason', 'preference_change')->first());
    }

    public function test_change_preference_fixed_schedule_checks_quota(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeTrip($a, $b, 'FIN-OUT7');
        $this->withRoundTripFare($a, $b);

        $route = $this->makeRoute($b, $a);
        $type = VehicleType::create(['name' => 'Fin plan', 'seat_count' => 50, 'active' => true]);
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
            'confirmed_return_quota' => 1,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ]);

        $result = $this->sellRoundTrip($outbound, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $user = $this->makeUser();
        $date = CarbonImmutable::now()->addDays(2)->toDateString();

        // Passe en fixed_schedule : quota 1 consommé par ce droit.
        $changed = app(ChangeReturnPreference::class)->change(
            $result['return'],
            $user,
            [
                'departure_schedule_id' => $schedule->id,
                'desired_travel_date' => $date,
            ],
        );
        $this->assertSame($schedule->id, $changed->departure_schedule_id);

        // Un second droit sur le même créneau → quota dépassé.
        $result2 = $this->sellRoundTrip($outbound, $a, $b, ['seat_number' => 2, 'return_mode' => TicketJourney::SELECTION_OPEN]);
        try {
            app(ChangeReturnPreference::class)->change(
                $result2['return'],
                $user,
                [
                    'departure_schedule_id' => $schedule->id,
                    'desired_travel_date' => $date,
                ],
            );
            $this->fail('Le quota de retours garantis doit être respecté au changement.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('return_quota_exceeded', $e->reasonCode);
        }
    }

    // =============================================================
    // Prolongation autorisée et auditée
    // =============================================================

    public function test_extend_return_is_audited_and_revives_expired(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT8');
        $result = $this->sellRoundTrip($trip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $return = $result['return'];
        $user = $this->makeUser();

        // Expire le retour.
        $return->update([
            'status' => TicketJourney::STATUS_EXPIRED,
            'valid_until' => CarbonImmutable::now()->subDay(),
        ]);

        $newUntil = CarbonImmutable::now()->addDays(15);
        $extended = app(ExtendReturnJourney::class)->extend($return, $user, $newUntil, 'Client hospitalisé');

        $this->assertNotSame(TicketJourney::STATUS_EXPIRED, $extended->status);
        $this->assertSame(TicketJourney::STATUS_PENDING, $extended->status);

        // Audit : auteur, motif, ancienne et nouvelle date.
        $prolongations = data_get($extended->settings, 'prolongations', []);
        $this->assertCount(1, $prolongations);
        $this->assertSame($user->id, $prolongations[0]['authorized_by']);
        $this->assertSame('Client hospitalisé', $prolongations[0]['reason']);
        $this->assertNotNull($prolongations[0]['previous_valid_until']);
        $this->assertSame($newUntil->startOfDay()->toDateString(), CarbonImmutable::parse($prolongations[0]['new_valid_until'])->toDateString());
    }

    public function test_extend_return_rejects_too_long_prolongation(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT9');
        $result = $this->sellRoundTrip($trip, $a, $b);

        try {
            app(ExtendReturnJourney::class)->extend(
                $result['return'],
                $this->makeUser(),
                CarbonImmutable::now()->addDays(180),
                'Test',
            );
            $this->fail('La prolongation ne peut pas dépasser 90 jours.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('prolongation_too_long', $e->reasonCode);
        }
    }

    // =============================================================
    // Ventilation analytique
    // =============================================================

    public function test_revenue_split_does_not_double_round_trip_revenue(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT10');
        $this->withRoundTripFare($a, $b);

        $this->sellRoundTrip($trip, $a, $b, ['return_mode' => TicketJourney::SELECTION_OPEN]);
        $ticket = Ticket::first();

        $split = app(RoundTripRevenueService::class)->splitTicket($ticket);

        // Invariant : part aller + part retour = montant encaissé (jamais doublé).
        $this->assertSame(5500, $split['amount_collected']);
        $this->assertSame($split['amount_collected'], $split['outbound_part'] + $split['return_part']);
        $this->assertGreaterThan(0, $split['outbound_part']);
        $this->assertGreaterThan(0, $split['return_part']);
        $this->assertSame(500, $split['discount_amount']);
    }

    public function test_revenue_aggregate_one_way_goes_fully_to_outbound(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT11');
        $this->sellRoundTrip($trip, $a, $b, ['journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY, 'seat_number' => 1]);

        $split = app(RoundTripRevenueService::class)->splitTicket(Ticket::first());

        $this->assertSame(3000, $split['outbound_part']);
        $this->assertSame(0, $split['return_part']);
        $this->assertSame(3000, $split['amount_collected']);
    }

    // =============================================================
    // HTTP : remboursement partiel
    // =============================================================

    public function test_http_refund_return(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FIN-OUT12');
        $this->withRoundTripFare($a, $b);
        $result = $this->sellRoundTrip($trip, $a, $b);
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/seller/tickets/'.$result['ticket']->id.'/refund-return', [
                'reason' => 'Retour non utilisé',
            ])
            ->assertCreated()
            ->assertJsonPath('journey.status', TicketJourney::STATUS_CANCELLED)
            ->assertJsonPath('refunded_amount', 2750);
    }

    // =============================================================
    // E. Remboursement complet : droits annulés, capacité libérée,
    //    scan/embarquement refusés, Okohi notifié
    // =============================================================

    private function executeFullRefund(Ticket $ticket, User $user): TicketCompensation
    {
        // Admin : la demande de compensation est exécutée directement.
        return app(TicketCompensationService::class)->request($ticket, [
            'incident_type' => 'other',
            'compensation_type' => 'refund',
            'amount' => $ticket->amount_collected,
            'reason' => 'Test remboursement complet',
        ], $user);
    }

    public function test_full_refund_cancels_all_non_consumed_journeys_and_frees_capacity(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'FULL-REFUND');
        $this->withRoundTripFare($a, $b);
        $user = $this->makeUser();

        $result = $this->sellRoundTrip($trip, $a, $b);
        $ticket = $result['ticket'];

        $this->executeFullRefund($ticket, $user);

        // Droits annulés (aller + retour).
        $ticket->refresh();
        $this->assertSame('refunded', $ticket->status);
        $this->assertSame(TicketJourney::STATUS_CANCELLED, $result['outbound']->fresh()->status);
        $this->assertSame(TicketJourney::STATUS_CANCELLED, $result['return']->fresh()->status);

        // Capacité libérée : plus aucune occupation.
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $trip->id)->count());

        // Prix historiques conservés.
        $this->assertSame($result['ticket']->price, $ticket->price);
        $this->assertSame($result['ticket']->gross_amount, $ticket->gross_amount);
    }

    public function test_full_refund_frees_guaranteed_return_quota(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeTrip($a, $b, 'QUOTA-OUT');
        $returnTrip = $this->makeTrip($b, $a, 'QUOTA-RET');
        $this->withRoundTripFare($a, $b);
        $user = $this->makeUser();

        $schedule = DepartureSchedule::create([
            'station_id' => $b->id,
            'route_id' => $returnTrip->route_id,
            'origin_station_id' => $b->id,
            'destination_station_id' => $a->id,
            'departure_time' => '18:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'confirmed_return_quota' => 2,
            'default_vehicle_type_id' => $returnTrip->vehicle->vehicle_type_id,
            'active' => true,
            'created_by' => $user->id,
        ]);

        $result = $this->sellRoundTrip($outbound, $a, $b, [
            'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
            'return_schedule_id' => $schedule->id,
            'return_date' => CarbonImmutable::now()->addDay()->toDateString(),
        ]);
        $ticket = $result['ticket'];

        $usedBefore = app(ReturnQuotaService::class)->guaranteedUsed($schedule, CarbonImmutable::now()->addDay()->toDateString());
        $this->assertSame(1, $usedBefore);

        $this->executeFullRefund($ticket, $user);

        $usedAfter = app(ReturnQuotaService::class)->guaranteedUsed($schedule, CarbonImmutable::now()->addDay()->toDateString());
        $this->assertSame(0, $usedAfter, 'Le remboursement complet doit libérer le quota garanti.');
    }

    public function test_full_refunded_ticket_is_rejected_by_scan_and_boarding(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'SCAN-REFUNDED');
        $this->withRoundTripFare($a, $b);
        $user = $this->makeUser();

        $result = $this->sellRoundTrip($trip, $a, $b);
        $this->executeFullRefund($result['ticket'], $user);

        // Scan en ligne refusé.
        $scan = app(ResolveScannedJourney::class)->resolve(
            $result['ticket']->qrPayloadString(),
            $trip,
        );
        $this->assertSame(ResolveScannedJourney::TICKET_REFUNDED, $scan['code']);

        // Embarquement direct refusé (billet parent refunded).
        try {
            app(BoardTicketJourney::class)->execute(
                $this->makeCrewMember(),
                $trip,
                $result['outbound']->fresh(),
            );
            $this->fail('L\'embarquement d\'un billet remboursé doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('ticket_not_boardable', $e->reasonCode);
        }
    }

    public function test_full_refund_publication_contains_refunded_statuses(): void
    {
        Http::fake();

        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'OKOHI-REFUNDED');
        $this->withRoundTripFare($a, $b);
        $user = $this->makeUser();

        $result = $this->sellRoundTrip($trip, $a, $b, ['okohi_customer_number' => 'OKOHI-123']);
        $this->executeFullRefund($result['ticket'], $user);

        // En production, DB::afterCommit déclenche la publication. Sous
        // RefreshDatabase, la transaction du test est rollbackée : on appelle
        // le publisher directement (même code que le callback afterCommit).
        app(OkohiTicketPublisher::class)->enqueue(
            $result['ticket']->fresh(),
            OkohiTicketOutbox::OPERATION_UPDATE,
        );

        // L'outbox contient une mise à jour avec les statuts annulés.
        $outbox = OkohiTicketOutbox::where('ticket_id', $result['ticket']->id)
            ->where('operation', OkohiTicketOutbox::OPERATION_UPDATE)
            ->latest()
            ->first();

        $this->assertNotNull($outbox, 'Une mise à jour Okohi doit être publiée après le remboursement.');
        $this->assertSame('cancelled', $outbox->payload['status']['outbound']);
        $this->assertSame('cancelled', $outbox->payload['status']['return']);
        $this->assertSame('refunded', $outbox->payload['status']['ticket']);
    }

    private function makeCrewMember(): \App\Models\CrewMember
    {
        static $i = 0;
        $i++;

        return \App\Models\CrewMember::create([
            'name' => 'Contrôle E'.$i,
            'phone' => '22507000000'.$i,
            'role' => 'driver',
            'active' => true,
        ]);
    }
}
