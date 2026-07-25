<?php

namespace Tests\Feature;

use App\Events\SeatMapUpdated;
use App\Models\AuthorizedDevice;
use App\Models\CrewMember;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStopOrder;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Models\TicketConnection;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Models\VehicleType;
use App\Services\OfflineCacheSigner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrewControlApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([SeatMapUpdated::class]);
        $this->ensureCrewApiTablesExist();
    }

    public function test_crew_member_can_log_in_and_fetch_profile(): void
    {
        [$crewMember, $trip] = $this->crewFixture();

        $login = $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
            'device_name' => 'Test Device',
        ])->assertOk();

        $token = $login->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/crew/me')
            ->assertOk()
            ->assertJsonPath('crew_member.id', $crewMember->id)
            ->assertJsonPath('crew_member.today_trips.0.id', $trip->id);
    }

    public function test_control_device_requires_tenant_approval_and_revocation_is_immediate(): void
    {
        [$crewMember] = $this->crewFixture();
        $operational = OperationalSetting::current();
        $operational->update([
            'settings' => ['device_restrictions' => ['control' => true, 'web' => false]],
        ]);

        $deviceId = (string) Str::uuid();
        $payload = [
            'phone' => $crewMember->phone,
            'pin' => '1234',
            'device_name' => 'Tablette quai 2',
            'device_id' => $deviceId,
            'device_secret' => str_repeat('a', 64),
            'device_platform' => 'android',
        ];

        $this->postJson('/api/crew/login', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'DEVICE_APPROVAL_REQUIRED')
            ->assertJsonPath('request_id', $deviceId);

        $this->assertDatabaseHas('authorized_devices', [
            'id' => $deviceId,
            'channel' => 'control',
            'status' => 'pending',
            'requested_by_id' => $crewMember->id,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        AuthorizedDevice::query()->findOrFail($deviceId)->update([
            'status' => AuthorizedDevice::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        $token = $this->postJson('/api/crew/login', $payload)
            ->assertOk()
            ->json('access_token');

        $storedToken = PersonalAccessToken::findToken($token);
        $this->assertSame($deviceId, $storedToken->authorized_device_id);

        AuthorizedDevice::query()->findOrFail($deviceId)->update([
            'status' => AuthorizedDevice::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/crew/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'DEVICE_REVOKED');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $storedToken->id]);
    }

    public function test_crew_member_can_log_in_with_or_without_spaces_in_phone(): void
    {
        [$crewMember, $trip] = $this->crewFixture();

        // 1. Database has "+2250700000001". Login with spaces "+225 0700 0000 01"
        $this->postJson('/api/crew/login', [
            'phone' => '+225 0700 0000 01',
            'pin' => '1234',
        ])->assertOk();

        // 2. Modify database member to have spaces: "+225 0700 0000 01"
        $crewMember->update(['phone' => '+225 0700 0000 01']);

        // Login without spaces "+2250700000001"
        $this->postJson('/api/crew/login', [
            'phone' => '+2250700000001',
            'pin' => '1234',
        ])->assertOk();

        // 3. Login with local number "0700000001" (omitting country code)
        $this->postJson('/api/crew/login', [
            'phone' => '0700000001',
            'pin' => '1234',
        ])->assertOk();

        // 4. Login with "2250700000001" (country code without +)
        $this->postJson('/api/crew/login', [
            'phone' => '2250700000001',
            'pin' => '1234',
        ])->assertOk();
    }

    public function test_crew_phone_is_canonicalized_when_saved(): void
    {
        [$crewMember] = $this->crewFixture();

        $crewMember->update(['phone' => '07 00 00 00 01']);

        $this->assertSame('+2250700000001', $crewMember->fresh()->phone);
    }

    public function test_crew_login_is_locked_after_repeated_failures(): void
    {
        [$crewMember] = $this->crewFixture();
        config()->set('transport.crew_auth.max_login_attempts', 3);
        config()->set('transport.crew_auth.lockout_seconds', 120);
        config()->set('transport.crew_auth.login_backoff_base_seconds', 0);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/crew/login', [
                'phone' => $crewMember->phone,
                'pin' => '9999',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
        ])->assertTooManyRequests()
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_crew_login_backoff_is_scoped_by_device_and_failures_are_logged(): void
    {
        [$crewMember] = $this->crewFixture();
        config()->set('transport.crew_auth.max_login_attempts', 5);
        config()->set('transport.crew_auth.login_backoff_base_seconds', 10);
        config()->set('transport.crew_auth.login_backoff_max_seconds', 60);
        Log::spy();

        $payload = [
            'phone' => $crewMember->phone,
            'pin' => '9999',
            'device_name' => 'Téléphone test',
            'device_id' => 'device-a',
        ];

        $this->postJson('/api/crew/login', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('retry_after', 10);

        $this->postJson('/api/crew/login', $payload)
            ->assertTooManyRequests();

        $this->postJson('/api/crew/login', [...$payload, 'device_id' => 'device-b'])
            ->assertUnprocessable();

        Log::shouldHaveReceived('warning')
            ->with('crew_login_failed', \Mockery::on(fn (array $context) => isset($context['phone_hash'], $context['device_hash'], $context['attempt']) && ! isset($context['phone'])))
            ->twice();
    }

    public function test_inactive_crew_token_and_token_without_crew_ability_are_rejected(): void
    {
        [$crewMember] = $this->crewFixture();
        config()->set('transport.crew_auth.token_inactivity_days', 14);
        $inactiveToken = $this->loginTokenFor($crewMember);
        PersonalAccessToken::findToken($inactiveToken)?->forceFill([
            'last_used_at' => now()->subDays(15),
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$inactiveToken)
            ->getJson('/api/crew/me')
            ->assertUnauthorized();

        $wrongAbility = $crewMember->createToken('Wrong channel', ['ticketing'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$wrongAbility)
            ->getJson('/api/crew/me')
            ->assertForbidden();

        $this->assertNull(PersonalAccessToken::findToken($wrongAbility));
    }

    public function test_same_device_login_replaces_previous_session_and_other_sessions_can_be_revoked(): void
    {
        [$crewMember] = $this->crewFixture();

        $firstToken = $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
            'device_name' => 'Téléphone équipage',
            'device_id' => 'device-a',
        ])->assertOk()->json('access_token');

        $secondToken = $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
            'device_name' => 'Téléphone équipage',
            'device_id' => 'device-a',
        ])->assertOk()->json('access_token');

        $this->withHeader('Authorization', 'Bearer '.$firstToken)
            ->getJson('/api/crew/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->getJson('/api/crew/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.current', true);

        $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
            'device_name' => 'Tablette équipage',
            'device_id' => 'device-b',
        ])->assertOk();

        $staleToken = $crewMember->createToken('Ancien téléphone#stale', ['crew'])->plainTextToken;
        PersonalAccessToken::findToken($staleToken)?->forceFill(['last_used_at' => now()->subDays(15)])->save();

        $sessions = $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->getJson('/api/crew/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonStructure(['sessions' => [['id', 'name', 'current', 'inactive_expires_at', 'expires_at']]])
            ->json('sessions');
        $this->assertNull(PersonalAccessToken::findToken($staleToken));
        $otherSessionId = collect($sessions)->firstWhere('current', false)['id'];

        $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->deleteJson("/api/crew/sessions/{$otherSessionId}")
            ->assertOk();

        $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
            'device_name' => 'Tablette de remplacement',
            'device_id' => 'device-c',
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->postJson('/api/crew/sessions/logout-others')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$secondToken)
            ->getJson('/api/crew/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'sessions');
    }

    public function test_deactivated_crew_member_loses_existing_session(): void
    {
        [$crewMember] = $this->crewFixture();
        $token = $this->loginTokenFor($crewMember);

        $crewMember->update(['active' => false]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/crew/me')
            ->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_public_catalog_is_minimal_and_operational_api_rejects_crew_tokens(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        $catalog = $this->getJson('/api/trips/'.$trip->route_id.'/'.$trip->departure_at->format('Y-m-d'))
            ->assertOk()
            ->assertJsonPath('0.id', $trip->id)
            ->assertJsonMissingPath('0.tickets_count')
            ->assertJsonMissingPath('0.vehicle.id')
            ->assertJsonMissingPath('0.vehicle.identifier')
            ->assertJsonMissingPath('0.route.route_stop_orders');

        $this->assertStringContainsString('public', (string) $catalog->headers->get('Cache-Control'));
        $this->assertSame('60', $catalog->headers->get('X-RateLimit-Limit'));

        $this->getJson('/api/vehicles')->assertUnauthorized();
        $this->getJson("/api/trips/{$trip->id}/occupancy")->assertUnauthorized();

        $token = $this->loginTokenFor($crewMember);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/vehicles')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tickets', [])
            ->assertForbidden();

        $restrictedUser = User::factory()->create(['role' => 'admin', 'active' => true]);
        $restrictedUser->setAttribute('role', 'seller');
        Sanctum::actingAs($restrictedUser, ['api']);
        $this->getJson('/api/dashboard/stats')->assertForbidden();
        $this->app['auth']->forgetGuards();

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $adminToken = $admin->createToken('Admin API', ['api'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson('/api/tickets', [])
            ->assertUnprocessable();
    }

    public function test_crew_member_can_report_status_and_view_latest_position(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        $token = $this->loginTokenFor($crewMember);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/status-reports", [
                'status' => 'traffic_jam',
                'latitude' => 5.336,
                'longitude' => -4.026,
                'note' => 'Embouteillage important',
            ])
            ->assertCreated()
            ->assertJsonPath('report.status', 'traffic_jam');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/crew/trips/{$trip->id}/latest-position")
            ->assertOk()
            ->assertJsonPath('report.status', 'traffic_jam');
    }

    public function test_crew_member_can_sell_and_board_tickets_when_sales_are_enabled(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update([
            'settings' => ['allow_crew_sales' => true],
        ]);
        $trip->update([
            'settings' => ['allow_crew_sales' => true],
        ]);

        $token = $this->loginTokenFor($crewMember);

        $sale = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                'from_station_id' => $stations['a'],
                'to_station_id' => $stations['c'],
                'seat_number' => 1,
                'passenger_name' => 'Jean Crew',
                'passenger_phone' => '+22501010101',
            ])
            ->assertCreated();

        $ticketId = $sale->json('ticket.id');

        $this->assertDatabaseHas('tickets', [
            'id' => $ticketId,
            'trip_id' => $trip->id,
            'crew_member_id' => $crewMember->id,
            'seat_number' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/crew/trips/{$trip->id}/tickets/{$ticketId}/board")
            ->assertOk()
            ->assertJsonPath('ticket.boarded_by.id', $crewMember->id);
    }

    public function test_crew_cannot_sell_before_boarding_has_started(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        $trip->update(['status' => 'scheduled']);
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $token = $this->loginTokenFor($crewMember);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                'from_station_id' => $stations['a'],
                'to_station_id' => $stations['c'],
                'seat_number' => 1,
                'passenger_name' => 'Vente trop tôt',
                'passenger_phone' => '+22501010101',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'crew_sales_wrong_status');
    }

    public function test_crew_trip_payload_exposes_calculated_permissions_and_rejects_skipped_transition(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $token = $this->loginTokenFor($crewMember);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/crew/trips/{$trip->id}")
            ->assertOk()
            ->assertJsonPath('trip.permissions.can_sell_on_board', true)
            ->assertJsonPath('trip.permissions.can_board', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/crew/trips/{$trip->id}/status", ['status' => 'arrived'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_trip_transition');

        $this->assertSame('boarding', $trip->fresh()->status);
    }

    public function test_assignment_end_is_exclusive_for_scheduled_trip_access(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        $trip->update(['status' => 'scheduled']);
        VehicleCrewAssignment::where('crew_member_id', $crewMember->id)->update([
            'assigned_to' => $trip->departure_at,
        ]);
        $token = $this->loginTokenFor($crewMember);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/crew/trips/{$trip->id}")
            ->assertForbidden();
    }

    public function test_delayed_trip_from_previous_calendar_day_remains_visible(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        $trip->update([
            'status' => 'delayed',
            'departure_at' => now()->subHours(8),
        ]);
        $token = $this->loginTokenFor($crewMember);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/crew/trips')
            ->assertOk()
            ->assertJsonPath('trips.0.id', $trip->id)
            ->assertJsonPath('trips.0.status', 'delayed');
    }

    public function test_expired_assignment_blocks_trip_messages_positions_and_tickets_consistently(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        VehicleCrewAssignment::where('crew_member_id', $crewMember->id)->update([
            'assigned_to' => now()->subHour(),
        ]);
        $trip->update(['departure_at' => now()]);
        $token = $this->loginTokenFor($crewMember);

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers)->getJson("/api/crew/trips/{$trip->id}")->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/crew/trips/{$trip->id}/tickets")->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/crew/trips/{$trip->id}/messages")->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/crew/trips/{$trip->id}/latest-position")->assertForbidden();
    }

    public function test_cancelled_ticket_cannot_be_boarded_online_or_offline(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $token = $this->loginTokenFor($crewMember);

        $ticketId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                'from_station_id' => $stations['a'],
                'to_station_id' => $stations['c'],
                'seat_number' => 1,
                'passenger_name' => 'Ticket annulé',
                'passenger_phone' => '+22501010101',
            ])->assertCreated()->json('ticket.id');

        Ticket::findOrFail($ticketId)->update(['status' => 'cancelled']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/crew/trips/{$trip->id}/tickets/{$ticketId}/board")
            ->assertStatus(409)
            ->assertJsonPath('code', 'ticket_invalid_status');

        $actionId = '8148c04b-2c1a-4dce-b8e9-81c7fc9f9232';
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sync", [
                'boardings' => [[
                    'client_action_id' => $actionId,
                    'ticket_id' => $ticketId,
                    'boarded_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.failed.0.code', 'ticket_invalid_status');

        $this->assertDatabaseHas('crew_offline_actions', ['id' => $actionId, 'type' => 'boarding']);
        $this->assertNull(Ticket::findOrFail($ticketId)->boarded_at);
    }

    public function test_offline_sale_sync_is_idempotent(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $token = $this->loginTokenFor($crewMember);
        $actionId = 'aef6de01-5f46-4c1c-9083-17edbed36e77';
        $payload = [
            'sales' => [[
                'client_action_id' => $actionId,
                'from_station_id' => $stations['a'],
                'to_station_id' => $stations['c'],
                'seat_number' => 2,
                'passenger_name' => 'Awa Offline',
                'passenger_phone' => '+22502020202',
            ]],
        ];

        $firstTicketId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sync", $payload)
            ->assertOk()
            ->assertJsonPath('results.sold.0.client_action_id', $actionId)
            ->json('results.sold.0.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sync", $payload)
            ->assertOk()
            ->assertJsonPath('results.sold.0.id', $firstTicketId);

        $this->assertSame(1, Ticket::where('trip_id', $trip->id)->where('seat_number', 2)->count());
        $storedAction = DB::table('crew_offline_actions')->where('id', $actionId)->first();
        $this->assertSame('confirmed', $storedAction->status);
        $this->assertSame(64, strlen($storedAction->payload_hash));
        $this->assertNotNull($storedAction->processed_at);
        $this->assertNotNull($storedAction->expires_at);

        $changedPayload = $payload;
        $changedPayload['sales'][0]['seat_number'] = 3;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sync", $changedPayload)
            ->assertOk()
            ->assertJsonPath('results.failed.0.code', 'client_action_id_reused');
        $this->assertSame(0, Ticket::where('trip_id', $trip->id)->where('seat_number', 3)->count());
    }

    public function test_conflicting_offline_sale_requires_crew_confirmation_before_reassignment(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $token = $this->loginTokenFor($crewMember);

        $sale = [
            'from_station_id' => $stations['a'],
            'to_station_id' => $stations['c'],
            'seat_number' => 1,
            'passenger_name' => 'Premier passager',
            'passenger_phone' => '+22503030303',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", $sale)
            ->assertCreated()
            ->assertJsonPath('ticket.seat_number', 1);

        $actionId = 'a730602d-ab29-4667-a5c1-9aac2cad6cf0';
        $conflict = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sync", [
                'sales' => [[
                    ...$sale,
                    'client_action_id' => $actionId,
                    'passenger_name' => 'Passager hors ligne',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.failed.0.client_action_id', $actionId)
            ->assertJsonPath('results.failed.0.code', 'seat_conflict')
            ->assertJsonPath('results.failed.0.http_status', 409)
            ->assertJsonStructure(['results' => ['failed' => [['suggested_seat']]]]);

        $this->assertSame(1, Ticket::where('trip_id', $trip->id)->count());
        $this->assertNotSame(1, $conflict->json('results.failed.0.suggested_seat'));

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sync", [
                'sales' => [[
                    ...$sale,
                    'client_action_id' => $actionId,
                    'passenger_name' => 'Passager hors ligne',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.failed.0.code', 'seat_conflict')
            ->assertJsonPath('results.failed.0.suggested_seat', $conflict->json('results.failed.0.suggested_seat'));

        $this->assertSame(1, DB::table('crew_offline_actions')->where('id', $actionId)->count());
        $this->assertDatabaseHas('crew_offline_actions', [
            'id' => $actionId,
            'status' => 'conflict',
            'error_code' => 'seat_conflict',
        ]);
        $this->assertSame(1, Ticket::where('trip_id', $trip->id)->count());
    }

    public function test_expired_offline_action_receipts_are_purged(): void
    {
        [$crewMember, $trip] = $this->crewFixture();
        $base = [
            'crew_member_id' => $crewMember->id,
            'trip_id' => $trip->id,
            'type' => 'boarding',
            'status' => 'confirmed',
            'result' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
            'attempt_count' => 1,
            'processed_at' => now()->subDays(8),
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ];

        DB::table('crew_offline_actions')->insert([
            [...$base, 'id' => '5c5edfb4-4c83-4976-a86d-e1eb2188a488', 'expires_at' => now()->subMinute()],
            [...$base, 'id' => '7dd9536a-bb78-479a-ad44-7b89d6906fc0', 'expires_at' => now()->addDay()],
        ]);

        $this->artisan('offline-actions:purge')
            ->expectsOutput('Purged 1 expired offline action receipt(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('crew_offline_actions', ['id' => '5c5edfb4-4c83-4976-a86d-e1eb2188a488']);
        $this->assertDatabaseHas('crew_offline_actions', ['id' => '7dd9536a-bb78-479a-ad44-7b89d6906fc0']);
    }

    public function test_offline_ticket_cache_is_minimal_versioned_and_signed(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $token = $this->loginTokenFor($crewMember);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                'from_station_id' => $stations['a'],
                'to_station_id' => $stations['c'],
                'seat_number' => 2,
                'passenger_name' => 'Donnée à ne pas cacher',
                'passenger_phone' => '+22502020202',
            ])->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/crew/trips/{$trip->id}/tickets")
            ->assertOk()
            ->assertJsonPath('offline_cache.schema_version', 2)
            ->assertJsonPath('offline_cache.signature_algorithm', 'Ed25519')
            ->assertJsonPath('offline_cache.trip_id', $trip->id)
            ->assertJsonMissingPath('offline_cache.tickets.0.passenger_name')
            ->assertJsonMissingPath('offline_cache.tickets.0.passenger_phone')
            ->assertJsonMissingPath('offline_cache.tickets.0.price');

        $cache = $response->json('offline_cache');
        $payload = collect($cache)->except([
            'payload_hash',
            'signature',
            'signature_algorithm',
            'key_id',
        ])->all();
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $verification = app(OfflineCacheSigner::class)->verificationDescriptor();

        $this->assertSame(hash('sha256', $encoded), $cache['payload_hash']);
        $this->assertSame($verification['key_id'], $cache['key_id']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($cache['signature'], true),
            $cache['payload_hash'],
            base64_decode($verification['public_key'], true),
        ));
        $this->assertGreaterThan(now(), Carbon::parse($cache['expires_at']));
    }

    public function test_crew_scan_and_offline_boarding_support_assigned_connection_ticket(): void
    {
        [$crewMember, $connectionTrip, $stations] = $this->crewFixture();
        $token = $this->loginTokenFor($crewMember);
        $inboundTrip = Trip::create([
            'route_id' => $connectionTrip->route_id,
            'vehicle_id' => $connectionTrip->vehicle_id,
            'origin_station_id' => $stations['a'],
            'destination_station_id' => $stations['b'],
            'departure_at' => today()->addHours(8),
            'status' => 'departed',
            'booking_type' => 'seat_assignment',
            'sales_control' => 'closed',
        ]);
        $ticket = Ticket::create([
            'ticket_number' => 'TKT-CONNECTION-MOBILE',
            'trip_id' => $inboundTrip->id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'seat_number' => 1,
            'from_station_id' => $stations['a'],
            'to_station_id' => $stations['b'],
            'final_destination_station_id' => $stations['c'],
            'transfer_station_id' => $stations['b'],
            'passenger_name' => 'Passager Correspondance',
            'passenger_phone' => '+22504040404',
            'price' => 5000,
            'status' => 'issued',
            'qr_code' => 'QR-CONNECTION-MOBILE',
        ]);
        $connection = TicketConnection::create([
            'ticket_id' => $ticket->id,
            'transfer_station_id' => $stations['b'],
            'destination_station_id' => $stations['c'],
            'route_id' => $connectionTrip->route_id,
            'trip_id' => $connectionTrip->id,
            'seat_number' => 2,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        TripSeatOccupancy::create([
            'trip_id' => $connectionTrip->id,
            'ticket_id' => $ticket->id,
            'seat_number' => 2,
            'from_station_id' => $stations['b'],
            'to_station_id' => $stations['c'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/crew/tickets/scan', [
                'qr_payload' => $ticket->ticket_number,
                'trip_id' => $connectionTrip->id,
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('ticket.is_connection_segment', true)
            ->assertJsonPath('ticket.connection.trip_id', $connectionTrip->id)
            ->assertJsonPath('ticket.seat_number', 2);

        $actionId = 'b840602d-ab29-4667-a5c1-9aac2cad6cf0';
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/crew/trips/{$connectionTrip->id}/tickets/sync", [
                'boardings' => [[
                    'client_action_id' => $actionId,
                    'ticket_id' => $ticket->id,
                    'boarded_at' => now()->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.boarded.0.client_action_id', $actionId)
            ->assertJsonPath('results.boarded.0.connection.status', 'boarded');

        $connection->refresh();
        $this->assertSame('boarded', $connection->status);
        $this->assertSame($crewMember->id, $connection->boarded_by);
        $this->assertNull($ticket->fresh()->boarded_at, 'L’embarquement de correspondance ne doit pas modifier le premier segment.');
    }

    public function test_seller_compensation_is_traced_on_same_ticket_and_visible_during_scan(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $crewToken = $this->loginTokenFor($crewMember);
        $ticketId = $this->withHeader('Authorization', 'Bearer '.$crewToken)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                'from_station_id' => $stations['a'], 'to_station_id' => $stations['c'],
                'seat_number' => 3, 'passenger_name' => 'Compensé Mobile', 'passenger_phone' => '+22505050505',
            ])->assertCreated()->json('ticket.id');
        $seller = User::factory()->create(['role' => 'admin', 'active' => true]);
        $operational = OperationalSetting::current();
        $operational->update(['settings' => array_merge($operational->settings ?? [], ['seller_compensation_enabled' => true])]);

        $this->actingAs($seller)->postJson("/seller/tickets/{$ticketId}/compensations", [
            'incident_type' => 'commercial', 'compensation_type' => 'credit',
            'amount' => 1000, 'reason' => 'Geste commercial validé en gare.',
        ])->assertCreated()->assertJsonPath('compensation.status', 'executed');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$crewToken)
            ->postJson('/api/crew/tickets/scan', ['qr_payload' => Ticket::findOrFail($ticketId)->ticket_number, 'trip_id' => $trip->id])
            ->assertOk()->assertJsonPath('valid', true)
            ->assertJsonPath('ticket.compensation.type', 'credit')
            ->assertJsonPath('ticket.compensation.amount', 1000);
        $this->assertSame(2500, Ticket::findOrFail($ticketId)->price);
    }

    public function test_executed_refund_invalidates_ticket_frees_seat_and_is_not_verifiable(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update([
            'okohi_integration_key' => 'test-integration-key',
            'settings' => ['allow_crew_sales' => true],
        ]);
        $crewToken = $this->loginTokenFor($crewMember);
        $ticketId = $this->withHeader('Authorization', 'Bearer '.$crewToken)
            ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                'from_station_id' => $stations['a'],
                'to_station_id' => $stations['c'],
                'seat_number' => 3,
                'passenger_name' => 'Passager remboursé',
                'passenger_phone' => '+22505050505',
            ])->assertCreated()->json('ticket.id');
        $ticket = Ticket::findOrFail($ticketId);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->postJson("/seller/tickets/{$ticketId}/compensations", [
            'incident_type' => 'trip_cancelled',
            'compensation_type' => 'refund',
            'reason' => 'Voyage annulé par l’exploitant.',
        ])->assertCreated()
            ->assertJsonPath('compensation.status', 'executed')
            ->assertJsonPath('compensation.amount', 2500);

        $this->assertSame('refunded', $ticket->fresh()->status);
        $this->assertDatabaseMissing('trip_seat_occupancies', ['ticket_id' => $ticketId]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Okohi-Integration-Key', 'test-integration-key')
            ->getJson('/api/okohi/verify?ticket_id='.$ticket->ticket_number)
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    public function test_free_rebooking_reserves_replacement_seat_atomically(): void
    {
        [$crewMember, $trip, $stations] = $this->crewFixture();
        TicketSetting::getSettings()->update(['settings' => ['allow_crew_sales' => true]]);
        $crewToken = $this->loginTokenFor($crewMember);
        $replacementTrip = Trip::create([
            'route_id' => $trip->route_id,
            'vehicle_id' => $trip->vehicle_id,
            'origin_station_id' => $stations['a'],
            'destination_station_id' => $stations['c'],
            'departure_at' => now()->addDay(),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment',
            'sales_control' => 'open',
        ]);

        $sell = function (int $seat, string $name) use ($crewToken, $trip, $stations): string {
            return $this->withHeader('Authorization', 'Bearer '.$crewToken)
                ->postJson("/api/crew/trips/{$trip->id}/tickets/sell", [
                    'from_station_id' => $stations['a'],
                    'to_station_id' => $stations['c'],
                    'seat_number' => $seat,
                    'passenger_name' => $name,
                    'passenger_phone' => '+22505050505',
                ])->assertCreated()->json('ticket.id');
        };

        $firstTicketId = $sell(3, 'Premier replacement');
        $secondTicketId = $sell(4, 'Second replacement');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->postJson("/seller/tickets/{$firstTicketId}/compensations", [
            'incident_type' => 'trip_cancelled',
            'compensation_type' => 'free_rebooking',
            'reason' => 'Replacement confirmé.',
            'replacement_trip_id' => $replacementTrip->id,
            'replacement_seat_number' => 2,
        ])->assertCreated()->assertJsonPath('compensation.status', 'executed');

        $this->assertDatabaseHas('trip_seat_occupancies', [
            'trip_id' => $replacementTrip->id,
            'ticket_id' => $firstTicketId,
            'seat_number' => 2,
            'from_station_id' => $stations['a'],
            'to_station_id' => $stations['c'],
        ]);

        $replacementTrip->update(['status' => 'boarding']);
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$crewToken)
            ->getJson("/api/crew/trips/{$replacementTrip->id}/tickets")
            ->assertOk()
            ->assertJsonPath('tickets.0.id', $firstTicketId)
            ->assertJsonPath('tickets.0.seat_number', 2);

        $ticket = Ticket::findOrFail($firstTicketId);
        $this->withHeader('Authorization', 'Bearer '.$crewToken)
            ->postJson('/api/crew/tickets/scan', [
                'qr_payload' => $ticket->ticket_number,
                'trip_id' => $replacementTrip->id,
            ])->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('ticket.seat_number', 2);

        $this->withHeader('Authorization', 'Bearer '.$crewToken)
            ->patchJson("/api/crew/trips/{$replacementTrip->id}/tickets/{$firstTicketId}/board")
            ->assertOk()
            ->assertJsonPath('ticket.boarded_at', fn ($value) => filled($value));

        $this->assertDatabaseHas('ticket_compensations', [
            'ticket_id' => $firstTicketId,
            'replacement_trip_id' => $replacementTrip->id,
            'boarded_by' => $crewMember->id,
        ]);
        $this->assertNull($ticket->fresh()->boarded_at);

        $this->actingAs($admin)->postJson("/seller/tickets/{$secondTicketId}/compensations", [
            'incident_type' => 'trip_cancelled',
            'compensation_type' => 'free_rebooking',
            'reason' => 'Tentative sur la même place.',
            'replacement_trip_id' => $replacementTrip->id,
            'replacement_seat_number' => 2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('replacement_seat_number');

        $this->assertSame(1, TripSeatOccupancy::where('trip_id', $replacementTrip->id)->where('seat_number', 2)->count());
        $this->assertSame(1, TicketCompensation::where('status', 'executed')->count());
    }

    private function loginTokenFor(CrewMember $crewMember): string
    {
        return $this->postJson('/api/crew/login', [
            'phone' => $crewMember->phone,
            'pin' => '1234',
        ])->assertOk()->json('access_token');
    }

    private function crewFixture(): array
    {
        $stations = $this->createStations();
        $route = Route::create([
            'name' => 'Gare A - Gare C',
            'origin_station_id' => $stations['a'],
            'destination_station_id' => $stations['c'],
            'active' => true,
        ]);

        RouteStopOrder::create([
            'route_id' => $route->id,
            'station_id' => $stations['a'],
            'stop_index' => 0,
        ]);
        RouteStopOrder::create([
            'route_id' => $route->id,
            'station_id' => $stations['b'],
            'stop_index' => 1,
        ]);
        RouteStopOrder::create([
            'route_id' => $route->id,
            'station_id' => $stations['c'],
            'stop_index' => 2,
        ]);

        RouteFare::create([
            'from_station_id' => $stations['a'],
            'to_station_id' => $stations['c'],
            'amount' => 2500,
            'is_bidirectional' => true,
            'active' => true,
        ]);

        $vehicleType = VehicleType::create([
            'name' => 'Mini',
            'seat_count' => 4,
            'seat_configuration' => '2+2',
            'door_positions' => [0],
            'last_row_seats' => 2,
            'active' => true,
        ]);

        $vehicle = Vehicle::create([
            'identifier' => 'BUS-CREW',
            'maker' => 'Toyota',
            'vehicle_type_id' => $vehicleType->id,
            'seat_count' => 4,
            'active' => true,
        ]);

        $crewMember = CrewMember::create([
            'name' => 'Moussa Driver',
            'phone' => '+2250700000001',
            'role' => 'driver',
            'pin' => '1234',
            'active' => true,
        ]);

        VehicleCrewAssignment::create([
            'vehicle_id' => $vehicle->id,
            'crew_member_id' => $crewMember->id,
            'role' => 'driver',
            'assigned_from' => now()->subDay(),
        ]);

        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $stations['a'],
            'destination_station_id' => $stations['c'],
            'departure_at' => now()->addHour(),
            'status' => 'boarding',
            'booking_type' => 'seat_assignment',
            'sales_control' => 'closed',
            'settings' => ['allow_crew_sales' => true],
        ]);

        return [$crewMember, $trip, $stations];
    }

    private function createStations(): array
    {
        $a = Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]);
        $b = Station::create(['name' => 'Gare B', 'code' => 'B', 'city' => 'B', 'active' => true]);
        $c = Station::create(['name' => 'Gare C', 'code' => 'C', 'city' => 'C', 'active' => true]);

        return ['a' => $a->id, 'b' => $b->id, 'c' => $c->id];
    }

    private function ensureCrewApiTablesExist(): void
    {
        if (! Schema::hasTable('crew_offline_actions')) {
            Schema::create('crew_offline_actions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('crew_member_id')->index();
                $table->uuid('trip_id')->index();
                $table->string('type', 20);
                $table->string('status', 20)->default('confirmed')->index();
                $table->char('payload_hash', 64)->nullable()->index();
                $table->json('request_payload')->nullable();
                $table->json('result');
                $table->unsignedSmallInteger('attempt_count')->default(1);
                $table->string('error_code', 80)->nullable()->index();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('authorized_devices')) {
            Schema::create('authorized_devices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->char('secret_hash', 64);
                $table->string('channel', 20)->index();
                $table->string('status', 20)->default('pending')->index();
                $table->string('name')->nullable();
                $table->string('platform')->nullable();
                $table->string('app_version')->nullable();
                $table->string('requested_by_type', 40)->nullable();
                $table->uuid('requested_by_id')->nullable()->index();
                $table->uuid('approved_by_user_id')->nullable()->index();
                $table->string('last_ip', 45)->nullable();
                $table->text('last_user_agent')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'authorized_device_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->uuid('authorized_device_id')->nullable()->index();
            });
        }

        if (! Schema::hasTable('ticket_settings')) {
            Schema::create('ticket_settings', function (Blueprint $table) {
                $table->id();
                $table->string('company_name')->default('TEST TRANSPORT');
                $table->json('phone_numbers')->nullable();
                $table->string('cc_label')->nullable();
                $table->json('footer_messages')->nullable();
                $table->text('baggage_policy_message')->nullable();
                $table->text('okohi_integration_url')->nullable();
                $table->string('okohi_integration_key')->nullable();
                $table->boolean('print_qr_code')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crew_members')) {
            Schema::create('crew_members', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->enum('role', ['driver', 'assistant'])->index();
                $table->string('license_number')->nullable();
                $table->date('license_expiry_date')->nullable();
                $table->string('pin')->nullable();
                $table->string('push_token')->nullable();
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('identifier');
                $table->string('maker')->nullable();
                $table->uuid('vehicle_type_id')->index();
                $table->unsignedInteger('seat_count');
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_crew_assignments')) {
            Schema::create('vehicle_crew_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('vehicle_id')->index();
                $table->uuid('crew_member_id')->index();
                $table->enum('role', ['driver', 'assistant'])->index();
                $table->dateTime('assigned_from');
                $table->dateTime('assigned_to')->nullable();
                $table->json('settings')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stations')) {
            Schema::create('stations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->string('city')->nullable();
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('routes')) {
            Schema::create('routes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->uuid('origin_station_id')->nullable();
                $table->uuid('destination_station_id')->nullable();
                $table->boolean('active')->default(true);
                $table->unsignedInteger('estimated_duration_minutes')->nullable();
                $table->boolean('automatic_connection_allocation')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('route_stop_orders')) {
            Schema::create('route_stop_orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('route_id')->index();
                $table->uuid('station_id')->index();
                $table->unsignedInteger('stop_index');
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('route_fares')) {
            Schema::create('route_fares', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('from_station_id')->index();
                $table->uuid('to_station_id')->index();
                $table->unsignedInteger('amount');
                $table->boolean('is_bidirectional')->default(true);
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vehicle_types')) {
            Schema::create('vehicle_types', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->json('seat_map')->nullable();
                $table->unsignedInteger('seat_count');
                $table->string('seat_configuration')->nullable();
                $table->unsignedInteger('last_row_seats')->nullable();
                $table->json('door_positions')->nullable();
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trips')) {
            Schema::create('trips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code')->nullable()->unique();
                $table->uuid('route_id')->index();
                $table->uuid('origin_station_id')->nullable();
                $table->uuid('destination_station_id')->nullable();
                $table->uuid('vehicle_id')->index();
                $table->dateTime('departure_at');
                $table->timestamp('planned_arrival_at')->nullable();
                $table->timestamp('actual_departed_at')->nullable();
                $table->timestamp('estimated_arrival_at')->nullable();
                $table->string('status')->default('scheduled');
                $table->string('booking_type')->default('seat_assignment');
                $table->string('sales_control')->default('closed');
                $table->boolean('allows_open_connections')->default(false);
                $table->boolean('automatic_connection_allocation')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('ticket_number')->unique();
                $table->uuid('trip_id')->index();
                $table->uuid('vehicle_id')->index();
                $table->unsignedInteger('seat_number')->index();
                $table->uuid('from_station_id')->index();
                $table->uuid('to_station_id')->index();
                $table->uuid('final_destination_station_id')->nullable()->index();
                $table->uuid('transfer_station_id')->nullable()->index();
                $table->string('passenger_name');
                $table->string('passenger_phone');
                $table->unsignedInteger('price');
                $table->uuid('seller_id')->nullable()->index();
                $table->uuid('crew_member_id')->nullable()->index();
                $table->uuid('station_id')->nullable()->index();
                $table->string('status')->default('issued')->index();
                $table->unsignedTinyInteger('boarding_group')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->json('qr_payload')->nullable();
                $table->string('qr_code')->nullable();
                $table->json('settings')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->uuid('cancelled_by')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trip_seat_occupancies')) {
            Schema::create('trip_seat_occupancies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->unsignedInteger('seat_number');
                $table->uuid('ticket_id')->nullable()->index();
                $table->uuid('from_station_id')->nullable()->index();
                $table->uuid('to_station_id')->nullable()->index();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_connections')) {
            Schema::create('ticket_connections', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_id')->unique();
                $table->uuid('transfer_station_id')->index();
                $table->uuid('destination_station_id')->index();
                $table->uuid('route_id')->nullable()->index();
                $table->uuid('trip_id')->nullable()->index();
                $table->unsignedInteger('seat_number')->nullable();
                $table->string('status')->default('pending')->index();
                $table->timestamp('planned_ready_at')->nullable();
                $table->timestamp('estimated_ready_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->uuid('assigned_by')->nullable();
                $table->string('assignment_mode')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_compensations')) {
            Schema::create('ticket_compensations', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('reference')->unique();
                $table->uuid('ticket_id')->index();
                $table->uuid('ticket_connection_id')->nullable();
                $table->string('incident_type');
                $table->string('compensation_type');
                $table->unsignedBigInteger('amount')->default(0);
                $table->string('status')->default('pending_approval');
                $table->text('reason');
                $table->uuid('requested_by');
                $table->uuid('approved_by')->nullable();
                $table->uuid('executed_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->uuid('replacement_trip_id')->nullable();
                $table->unsignedInteger('replacement_seat_number')->nullable();
                $table->timestamp('boarded_at')->nullable();
                $table->uuid('boarded_by')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operational_settings')) {
            Schema::create('operational_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('automatic_connection_allocation')->default(false);
                $table->unsignedInteger('connection_transfer_buffer_minutes')->default(15);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crew_status_reports')) {
            Schema::create('crew_status_reports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->uuid('crew_member_id')->index();
                $table->string('status');
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->text('note')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('reported_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crew_messages')) {
            Schema::create('crew_messages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->uuid('crew_member_id')->index();
                $table->string('type');
                $table->text('body')->nullable();
                $table->string('audio_path')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trip_status_logs')) {
            Schema::create('trip_status_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->string('status', 30)->index();
                $table->uuid('changed_by_user_id')->nullable()->index();
                $table->uuid('changed_by_crew_member_id')->nullable()->index();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }
    }
}
