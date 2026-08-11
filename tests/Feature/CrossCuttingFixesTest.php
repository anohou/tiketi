<?php

namespace Tests\Feature;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\DepartureScheduleException;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ExpireReturnJourneys;
use App\Services\ReturnJourneyAllocator;
use App\Services\SellRoundTripTicket;
use App\Services\ValidateFixedScheduleReturn;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class CrossCuttingFixesTest extends TestCase
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
        $type = VehicleType::create(['name' => 'Cross '.$counter, 'seat_count' => 50, 'active' => true]);
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

    private function makeReturnSchedule(Station $origin, Station $destination, array $overrides = []): DepartureSchedule
    {
        $route = $this->makeRoute($origin, $destination);
        $type = VehicleType::create(['name' => 'Retour type', 'seat_count' => 50, 'active' => true]);

        return DepartureSchedule::create(array_merge([
            'station_id' => $origin->id,
            'route_id' => $route->id,
            'origin_station_id' => $origin->id,
            'destination_station_id' => $destination->id,
            'departure_time' => '18:00',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
            'timezone' => 'UTC',
            'planned_capacity' => 50,
            'confirmed_return_quota' => 5,
            'default_vehicle_type_id' => $type->id,
            'vehicle_assignment_policy' => 'allow_planned_capacity',
            'active' => true,
        ], $overrides));
    }

    // =============================================================
    // E. Validation des retours programmés
    // =============================================================

    public function test_fixed_schedule_rejects_incompatible_return_route(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $c = $this->makeStation('Gare C', 'C');
        $trip = $this->makeTrip($a, $b, 'CROSS-1');

        // Programme de retour C → A : ne correspond pas au trajet inverse B → A.
        $schedule = $this->makeReturnSchedule($c, $a);
        $date = CarbonImmutable::now()->addDays(2)->toDateString();

        try {
            app(SellRoundTripTicket::class)->sell([
                'trip' => $trip,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
                'seat_number' => 1,
                'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
                'return_schedule_id' => $schedule->id,
                'return_date' => $date,
                'return_time' => '18:00',
                'passenger_name' => 'Test',
                'passenger_phone' => '+225****0000',
                'seller_id' => $this->makeUser()->id,
                'station_id' => $a->id,
                'fare_calculation' => null,
                'okohi_customer_number' => null,
                'okohi_reward_id' => null,
                'okohi_transaction_id' => null,
            ]);
            $this->fail('Un programme de retour non inverse doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('incompatible_return_route', $e->reasonCode);
        }
    }

    public function test_fixed_schedule_rejects_day_without_service(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CROSS-2');

        // Le programme ne circule que le dimanche (7).
        $schedule = $this->makeReturnSchedule($b, $a, ['days_of_week' => [7]]);
        $date = CarbonImmutable::now()->next(CarbonImmutable::MONDAY)->toDateString();

        try {
            app(SellRoundTripTicket::class)->sell([
                'trip' => $trip,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
                'seat_number' => 1,
                'return_mode' => TicketJourney::SELECTION_FIXED_SCHEDULE,
                'return_schedule_id' => $schedule->id,
                'return_date' => $date,
                'return_time' => '18:00',
                'passenger_name' => 'Test',
                'passenger_phone' => '+225****0000',
                'seller_id' => $this->makeUser()->id,
                'station_id' => $a->id,
                'fare_calculation' => null,
                'okohi_customer_number' => null,
                'okohi_reward_id' => null,
                'okohi_transaction_id' => null,
            ]);
            $this->fail('Une date sans circulation doit être refusée.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('schedule_not_on_day', $e->reasonCode);
        }
    }

    public function test_fixed_schedule_applies_time_changed_exception(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $schedule = $this->makeReturnSchedule($b, $a, ['departure_time' => '18:00']);
        $date = CarbonImmutable::now()->addDays(2)->toDateString();

        // Exception time_changed : le départ passe à 19:30.
        DepartureScheduleException::create([
            'departure_schedule_id' => $schedule->id,
            'service_date' => $date,
            'type' => DepartureScheduleException::TYPE_TIME_CHANGED,
            'replacement_time' => '19:30',
        ]);

        $result = app(ValidateFixedScheduleReturn::class)->validate(
            $schedule->id,
            $date,
            $a->id,
            $b->id,
        );

        // L'heure calculée vient de l'exception, pas du client.
        $this->assertSame('19:30', $result['departure_time']);
    }

    public function test_fixed_schedule_rejects_cancelled_exception(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $schedule = $this->makeReturnSchedule($b, $a);
        $date = CarbonImmutable::now()->addDays(2)->toDateString();

        DepartureScheduleException::create([
            'departure_schedule_id' => $schedule->id,
            'service_date' => $date,
            'type' => DepartureScheduleException::TYPE_CANCELLED,
        ]);

        try {
            app(ValidateFixedScheduleReturn::class)->validate($schedule->id, $date, $a->id, $b->id);
            $this->fail('Un départ annulé par exception doit être refusé.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('schedule_exception', $e->reasonCode);
        }
    }

    public function test_fixed_schedule_out_of_period_is_rejected_even_if_another_schedule_is_valid(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $date = CarbonImmutable::now()->addDays(2)->toDateString();

        // Programme sélectionné : HORS période (expiré hier).
        $expired = $this->makeReturnSchedule($b, $a, [
            'departure_time' => '18:00',
            'valid_from' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'valid_until' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        // Un AUTRE programme du même trajet reste valide ce jour-là : la
        // validation ne doit PAS se laisser tromper (bug G2).
        $this->makeReturnSchedule($b, $a, [
            'departure_time' => '19:00',
            'valid_from' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        try {
            app(ValidateFixedScheduleReturn::class)->validate($expired->id, $date, $a->id, $b->id);
            $this->fail('Un programme hors période doit être refusé même si un autre programme est valide.');
        } catch (TicketingRuleViolation $e) {
            $this->assertSame('schedule_not_valid_on_date', $e->reasonCode);
        }
    }

    // =============================================================
    // G. QR unique : papier = Okohi = scan
    // =============================================================

    public function test_paper_qr_matches_okohi_qr_even_when_okohi_enabled(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CROSS-QR');

        // Okohi activé (ce qui causait le bug : le papier encodait l'URL Okohi).
        TicketSetting::getSettings()->update([
            'okohi_integration_url' => 'https://okohi.test/scan/{ticket_id}',
            'okohi_integration_key' => 'secret',
        ]);

        $result = app(SellRoundTripTicket::class)->sell([
            'trip' => $trip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => 1,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Test',
            'passenger_phone' => '+225****0000',
            'seller_id' => $this->makeUser()->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => 'OKOHI-1',
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);
        $ticket = $result['ticket'];

        // Trois valeurs identiques : impression, payload Okohi, scan.
        $this->assertSame('TIKETI2|'.$ticket->public_token, $ticket->printableQrValue());
        $this->assertSame('TIKETI2|'.$ticket->public_token, $ticket->qrPayloadString());
        $this->assertSame('TIKETI2|'.$ticket->public_token, Ticket::resolveFromQrValue('TIKETI2|'.$ticket->public_token)->qrPayloadString());
    }

    // =============================================================
    // 18. Doublon outbound/return empêché
    // =============================================================

    public function test_duplicate_outbound_journey_is_rejected(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $trip = $this->makeTrip($a, $b, 'CROSS-DUP');
        $result = app(SellRoundTripTicket::class)->sell([
            'trip' => $trip,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ONE_WAY,
            'seat_number' => 1,
            'return_mode' => null,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Test',
            'passenger_phone' => '+225****0000',
            'seller_id' => $this->makeUser()->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);

        // La contrainte unique (ticket_id, direction) empêche un second outbound.
        try {
            DB::table('ticket_journeys')->insert([
                'id' => (string) Str::uuid(),
                'ticket_id' => $result['ticket']->id,
                'direction' => TicketJourney::DIRECTION_OUTBOUND,
                'from_station_id' => $a->id,
                'to_station_id' => $b->id,
                'selection_mode' => TicketJourney::SELECTION_FIXED_TRIP,
                'status' => TicketJourney::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Un second droit outbound doit être refusé par la contrainte.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, TicketJourney::where('ticket_id', $result['ticket']->id)->where('direction', TicketJourney::DIRECTION_OUTBOUND)->count());
    }

    // =============================================================
    // 19. Expiration des retours
    // =============================================================

    public function test_expire_return_journeys_marks_expired_and_frees_seat(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeTrip($a, $b, 'CROSS-EXP');
        $returnTrip = $this->makeTrip($b, $a, 'CROSS-EXP-R');
        $user = $this->makeUser();

        $result = app(SellRoundTripTicket::class)->sell([
            'trip' => $outbound,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => 1,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Test',
            'passenger_phone' => '+225****0000',
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);
        $return = $result['return'];

        app(ReturnJourneyAllocator::class)->assign($return, $returnTrip, 7, $user);

        // Expire le retour.
        $return->update(['valid_until' => CarbonImmutable::now()->subDay()]);

        $count = app(ExpireReturnJourneys::class)->expire();
        $this->assertSame(1, $count);

        $return->refresh();
        $this->assertSame(TicketJourney::STATUS_EXPIRED, $return->status);
        $this->assertNull($return->trip_id, 'L’affectation est libérée.');
        $this->assertNull($return->seat_number);
        $this->assertSame(0, TripSeatOccupancy::where('trip_id', $returnTrip->id)->count(), 'Le siège est libéré.');

        // Historique conservé.
        $this->assertNotNull($return->assignments()->where('reason', 'expired')->first());

        // Idempotent : rien à expirer la seconde fois.
        $this->assertSame(0, app(ExpireReturnJourneys::class)->expire());
    }

    // =============================================================
    // I. Vendeur d'une autre gare refusé (pool des retours)
    // =============================================================

    public function test_seller_from_another_station_cannot_manage_return_pool(): void
    {
        $a = $this->makeStation('Gare A', 'A');
        $b = $this->makeStation('Gare B', 'B');
        $outbound = $this->makeTrip($a, $b, 'CROSS-SELL');
        $user = $this->makeUser();

        $result = app(SellRoundTripTicket::class)->sell([
            'trip' => $outbound,
            'from_station_id' => $a->id,
            'to_station_id' => $b->id,
            'journey_type' => Ticket::JOURNEY_TYPE_ROUND_TRIP,
            'seat_number' => 1,
            'return_mode' => TicketJourney::SELECTION_OPEN,
            'return_schedule_id' => null,
            'return_date' => null,
            'return_time' => null,
            'passenger_name' => 'Test',
            'passenger_phone' => '+225****0000',
            'seller_id' => $user->id,
            'station_id' => $a->id,
            'fare_calculation' => null,
            'okohi_customer_number' => null,
            'okohi_reward_id' => null,
            'okohi_transaction_id' => null,
        ]);

        // Un vendeur affecté à une AUTRE gare (C) ne peut pas affecter ce retour.
        $c = $this->makeStation('Gare C', 'C');
        $otherSeller = User::factory()->create(['role' => 'seller', 'active' => true]);
        UserStationAssignment::create([
            'user_id' => $otherSeller->id,
            'station_id' => $c->id,
            'active' => true,
        ]);

        $this->actingAs($otherSeller)
            ->postJson('/seller/return-journeys/'.$result['return']->id.'/assign', [
                'trip_id' => $this->makeTrip($b, $a, 'CROSS-SELL-R')->id,
            ])
            ->assertForbidden();
    }
}
