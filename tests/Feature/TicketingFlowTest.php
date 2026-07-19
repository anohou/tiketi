<?php

namespace Tests\Feature;

use App\Events\SeatMapUpdated;
use App\Events\TripCreated;
use App\Jobs\CancelOrReverseOkohiClaimJob;
use App\Models\CrewMember;
use App\Models\OkohiRewardRequest;
use App\Models\OperationalSetting;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStopOrder;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\UserRouteAssignment;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Models\VehicleType;
use App\Services\AutomaticConnectionAllocator;
use App\Services\OpenConnectionService;
use App\Services\SeatMapService;
use App\Services\TripSegmentService;
use App\Services\TripTimingService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TicketingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureTenantTicketingTablesExist();
    }

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->create([
            'active' => false,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_semi_intelligent_trip_allows_same_seat_on_non_overlapping_segments(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture('semi_intelligent');

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['b']->id,
            'to_station_id' => $stations['c']->id,
            'seats' => [1],
        ])->assertCreated();

        $this->assertSame(2, Ticket::where('trip_id', $trip->id)->count());
        $this->assertSame(2, TripSeatOccupancy::where('trip_id', $trip->id)->where('seat_number', 1)->count());
    }

    public function test_open_connection_keeps_one_ticket_and_can_be_assigned_to_another_trip(): void
    {
        [$admin, $firstTrip, $stations] = $this->ticketingFixture();
        $firstTrip->update(['allows_open_connections' => true]);
        $secondTrip = Trip::create([
            'route_id' => $firstTrip->route_id,
            'vehicle_id' => $firstTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHours(3),
            'status' => 'boarding',
        ]);

        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $firstTrip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $firstTrip->route_id,
            'seats' => [1],
        ])->assertCreated();

        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $connection = $ticket->connection()->firstOrFail();
        $this->assertSame('pending', $connection->status);
        $this->assertSame($stations['c']->id, $ticket->final_destination_station_id);
        $this->assertSame(2000, $ticket->price, 'Le tarif doit être A→C, sans additionner ni utiliser A→B ou B→C.');

        $this->actingAs($admin)->patchJson("/seller/transfer-pool/{$connection->id}/ready")
            ->assertOk();
        $this->actingAs($admin)->postJson("/seller/trips/{$secondTrip->id}/assign-connection", [
            'connection_id' => $connection->id,
            'seat_number' => 2,
        ])->assertOk();

        $connection->refresh();
        $this->assertSame(1, Ticket::whereKey($ticket->id)->count());
        $this->assertSame($secondTrip->id, $connection->trip_id);
        $this->assertSame('assigned', $connection->status);
        $this->assertSame(2, $connection->seat_number);
        $this->assertSame('manual', $connection->assignment_mode);
        $this->assertDatabaseHas('trip_seat_occupancies', [
            'trip_id' => $secondTrip->id,
            'ticket_id' => $ticket->id,
            'from_station_id' => $stations['b']->id,
            'to_station_id' => $stations['c']->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/seller/trips/{$secondTrip->id}/details")
            ->assertOk()
            ->assertJsonPath('occupancies.0.id', $ticket->id)
            ->assertJsonPath('occupancies.0.seat_number', 2)
            ->assertJsonPath('occupancies.0.from_station_id', $stations['b']->id)
            ->assertJsonPath('occupancies.0.to_station_id', $stations['c']->id)
            ->assertJsonPath('occupancies.0.journey_type', 'connection')
            ->assertJsonPath('occupancies.0.connection_status', 'assigned');

        $this->actingAs($admin)
            ->getJson("/seller/trips/{$firstTrip->id}/details")
            ->assertOk()
            ->assertJsonPath('occupancies.0.journey_type', 'connection_origin')
            ->assertJsonPath('occupancies.0.final_destination.id', $stations['c']->id)
            ->assertJsonPath('occupancies.0.transfer_station.id', $stations['b']->id)
            ->assertJsonPath('occupancies.0.connection_trip.id', $secondTrip->id)
            ->assertJsonPath('occupancies.0.connection_status', 'assigned');
    }

    public function test_automatic_allocation_uses_expected_arrival_and_purchase_order(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true, 'departure_at' => now()->addHour()]);
        $inboundTrip->route->update(['estimated_duration_minutes' => 60]);
        OperationalSetting::current()->update([
            'automatic_connection_allocation' => false,
            'connection_transfer_buffer_minutes' => 10,
        ]);

        foreach ([1, 2] as $seat) {
            $this->actingAs($admin)->postJson('/seller/tickets', [
                'trip_id' => $inboundTrip->id,
                'from_station_id' => $stations['a']->id,
                'to_station_id' => $stations['b']->id,
                'final_destination_station_id' => $stations['c']->id,
                'connection_route_id' => $inboundTrip->route_id,
                'seats' => [$seat],
            ])->assertCreated();
        }

        app(TripTimingService::class)->markDeparted($inboundTrip, now());

        $outboundTrip = Trip::create([
            'route_id' => $inboundTrip->route_id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHours(2),
            'status' => 'scheduled',
            'automatic_connection_allocation' => true,
        ]);

        $assigned = app(AutomaticConnectionAllocator::class)->allocateForTrip($outboundTrip, $admin);

        $this->assertCount(2, $assigned);
        $this->assertEqualsCanonicalizing([1, 2], $assigned->pluck('seat_number')->all());
        $this->assertSame(['automatic'], $assigned->pluck('assignment_mode')->unique()->values()->all());
        $this->assertTrue($assigned->every(fn ($connection) => data_get($connection->settings, 'seat_allocation.mode') === 'intelligent'));
        $this->assertTrue($assigned->every(fn ($connection) => filled(data_get($connection->settings, 'seat_allocation.reason'))));
    }

    public function test_stale_automatic_allocator_cannot_reassign_an_already_assigned_connection(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true]);
        $ticketId = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $inboundTrip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $inboundTrip->route_id,
            'seats' => [1],
        ])->assertCreated()->json('ticket_ids.0');

        $firstOutbound = Trip::create([
            'route_id' => $inboundTrip->route_id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHours(2),
            'status' => 'scheduled',
        ]);
        $secondOutbound = $firstOutbound->replicate();
        $secondOutbound->departure_at = now()->addHours(3);
        $secondOutbound->save();

        $connection = Ticket::findOrFail($ticketId)->connection()->firstOrFail();
        $service = app(OpenConnectionService::class);
        $service->assign($connection, $firstOutbound, 2, $admin, 'automatic', false);

        try {
            $service->assign($connection, $secondOutbound, 3, $admin, 'automatic', false);
            $this->fail('Une allocation automatique obsolète ne doit pas réaffecter la correspondance.');
        } catch (ValidationException) {
            // Conflit attendu : la première affectation reste la source de vérité.
        }

        $connection->refresh();
        $this->assertSame($firstOutbound->id, $connection->trip_id);
        $this->assertSame(2, $connection->seat_number);
        $this->assertSame(1, $connection->assignmentHistory()->count());
        $this->assertDatabaseMissing('trip_seat_occupancies', [
            'trip_id' => $secondOutbound->id,
            'ticket_id' => $ticketId,
        ]);
    }

    public function test_marking_departure_recalculates_connection_estimate(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $trip->update(['allows_open_connections' => true]);
        $trip->route->update(['estimated_duration_minutes' => 60]);
        app(TripTimingService::class)->syncPlannedTimes($trip);
        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $trip->route_id,
            'seats' => [1],
        ])->assertCreated();
        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $plannedReadyAt = $ticket->connection()->firstOrFail()->planned_ready_at;
        $this->assertNotNull($plannedReadyAt);
        $this->assertNull($ticket->connection()->firstOrFail()->estimated_ready_at);
        $actualDeparture = now()->addMinutes(20)->startOfSecond();

        app(TripTimingService::class)->markDeparted($trip, $actualDeparture);

        $this->assertEquals($actualDeparture->copy()->addMinutes(30), $ticket->connection()->firstOrFail()->estimated_ready_at);
        $this->assertEquals($plannedReadyAt, $ticket->connection()->firstOrFail()->planned_ready_at);
        $this->assertEquals($actualDeparture->copy()->addMinutes(60), $trip->fresh()->estimated_arrival_at);
    }

    public function test_late_inbound_trip_flags_assigned_connection_without_releasing_its_seat(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true]);
        $inboundTrip->route->update(['estimated_duration_minutes' => 60]);
        OperationalSetting::current()->update(['connection_transfer_buffer_minutes' => 10]);
        $outboundTrip = Trip::create([
            'route_id' => $inboundTrip->route_id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addMinutes(35)->startOfSecond(),
            'status' => 'boarding',
        ]);

        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $inboundTrip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $inboundTrip->route_id,
            'seats' => [1],
        ])->assertCreated();
        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $connection = $ticket->connection()->firstOrFail();
        $this->actingAs($admin)->patchJson("/seller/transfer-pool/{$connection->id}/ready")->assertOk();
        $this->actingAs($admin)->postJson("/seller/trips/{$outboundTrip->id}/assign-connection", [
            'connection_id' => $connection->id,
            'seat_number' => 2,
        ])->assertOk();

        app(TripTimingService::class)->markDeparted($inboundTrip, now()->startOfSecond());

        $connection->refresh();
        $this->assertSame('assigned', $connection->status);
        $this->assertTrue((bool) data_get($connection->settings, 'has_conflict'));
        $this->assertNotNull(data_get($connection->settings, 'conflict_reason'));
        $this->assertDatabaseHas('trip_seat_occupancies', [
            'trip_id' => $outboundTrip->id,
            'ticket_id' => $ticket->id,
            'seat_number' => 2,
        ]);
    }

    public function test_departing_connection_trip_releases_unboarded_passenger_back_to_pool(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true]);
        $outboundTrip = Trip::create([
            'route_id' => $inboundTrip->route_id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHour(),
            'status' => 'boarding',
        ]);
        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $inboundTrip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $inboundTrip->route_id,
            'seats' => [1],
        ])->assertCreated();
        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $connection = $ticket->connection()->firstOrFail();
        $this->actingAs($admin)->postJson("/seller/trips/{$outboundTrip->id}/assign-connection", [
            'connection_id' => $connection->id,
            'seat_number' => 2,
        ])->assertOk();

        app(TripTimingService::class)->markDeparted($outboundTrip, now()->startOfSecond());

        $connection->refresh();
        $this->assertSame('pending', $connection->status);
        $this->assertNull($connection->trip_id);
        $this->assertNull($connection->seat_number);
        $this->assertDatabaseMissing('trip_seat_occupancies', [
            'trip_id' => $outboundTrip->id,
            'ticket_id' => $ticket->id,
        ]);
        $this->assertDatabaseHas('ticket_connection_assignments', [
            'ticket_connection_id' => $connection->id,
            'from_trip_id' => $outboundTrip->id,
            'action' => 'released_after_departure',
        ]);
    }

    public function test_conflicted_connection_can_be_reassigned_atomically_and_is_recalculated(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true]);
        $inboundTrip->route->update(['estimated_duration_minutes' => 60]);
        OperationalSetting::current()->update(['connection_transfer_buffer_minutes' => 10]);
        $earlyTrip = Trip::create([
            'route_id' => $inboundTrip->route_id, 'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id, 'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addMinutes(35)->startOfSecond(), 'status' => 'boarding',
        ]);
        $laterTrip = Trip::create([
            'route_id' => $inboundTrip->route_id, 'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id, 'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHours(2)->startOfSecond(), 'status' => 'scheduled',
        ]);
        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $inboundTrip->id, 'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id, 'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $inboundTrip->route_id, 'seats' => [1],
        ])->assertCreated();
        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $connection = $ticket->connection()->firstOrFail();
        $this->actingAs($admin)->postJson("/seller/trips/{$earlyTrip->id}/assign-connection", [
            'connection_id' => $connection->id, 'seat_number' => 2,
        ])->assertOk();
        app(TripTimingService::class)->markDeparted($inboundTrip, now()->startOfSecond());
        $this->assertTrue($connection->fresh()->hasConflict());

        $this->actingAs($admin)->postJson("/seller/trips/{$laterTrip->id}/assign-connection", [
            'connection_id' => $connection->id, 'seat_number' => 3,
        ])->assertOk();

        $connection->refresh();
        $this->assertSame($laterTrip->id, $connection->trip_id);
        $this->assertSame(3, $connection->seat_number);
        $this->assertFalse($connection->hasConflict());
        $this->assertDatabaseMissing('trip_seat_occupancies', ['trip_id' => $earlyTrip->id, 'ticket_id' => $ticket->id]);
        $this->assertDatabaseHas('trip_seat_occupancies', ['trip_id' => $laterTrip->id, 'ticket_id' => $ticket->id, 'seat_number' => 3]);
        $this->assertDatabaseHas('ticket_connection_assignments', [
            'ticket_connection_id' => $connection->id,
            'from_trip_id' => $earlyTrip->id,
            'to_trip_id' => $laterTrip->id,
            'action' => 'reassigned',
        ]);
    }

    public function test_arriving_connection_trip_completes_boarded_connections_only(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true]);
        $outboundTrip = Trip::create([
            'route_id' => $inboundTrip->route_id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHour(),
            'status' => 'departed',
        ]);
        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $inboundTrip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $inboundTrip->route_id,
            'seats' => [1],
        ])->assertCreated();
        $connection = Ticket::findOrFail($response->json('ticket_ids.0'))->connection()->firstOrFail();
        $connection->update([
            'trip_id' => $outboundTrip->id,
            'seat_number' => 2,
            'status' => 'boarded',
            'boarded_at' => now(),
        ]);
        $arrivedAt = now()->addHours(2)->startOfSecond();

        app(TripTimingService::class)->markArrived($outboundTrip, $arrivedAt);

        $connection->refresh();
        $this->assertSame('arrived', $outboundTrip->fresh()->status);
        $this->assertSame('completed', $connection->status);
        $this->assertEquals($arrivedAt, $connection->completed_at);
    }

    public function test_cancelled_connection_trip_releases_assigned_passenger_and_records_history(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $inboundTrip->update(['allows_open_connections' => true]);
        $outboundTrip = Trip::create([
            'route_id' => $inboundTrip->route_id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stations['c']->id,
            'departure_at' => now()->addHour(),
            'status' => 'boarding',
        ]);
        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $inboundTrip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'final_destination_station_id' => $stations['c']->id,
            'connection_route_id' => $inboundTrip->route_id,
            'seats' => [1],
        ])->assertCreated();
        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $connection = $ticket->connection()->firstOrFail();
        $this->actingAs($admin)->patchJson("/seller/transfer-pool/{$connection->id}/ready")->assertOk();
        $this->actingAs($admin)->postJson("/seller/trips/{$outboundTrip->id}/assign-connection", [
            'connection_id' => $connection->id,
            'seat_number' => 2,
        ])->assertOk();

        app(TripTimingService::class)->markCancelled($outboundTrip);

        $connection->refresh();
        $this->assertSame('cancelled', $outboundTrip->fresh()->status);
        $this->assertSame('ready', $connection->status);
        $this->assertNull($connection->trip_id);
        $this->assertNull($connection->seat_number);
        $this->assertDatabaseMissing('trip_seat_occupancies', [
            'trip_id' => $outboundTrip->id,
            'ticket_id' => $ticket->id,
        ]);
        $this->assertDatabaseHas('ticket_connection_assignments', [
            'ticket_connection_id' => $connection->id,
            'from_trip_id' => $outboundTrip->id,
            'action' => 'released_after_cancellation',
        ]);
    }

    public function test_different_destinations_on_same_connection_route_share_the_same_trip(): void
    {
        [$admin, $inboundTrip, $stations] = $this->ticketingFixture();
        $stationD = Station::create(['name' => 'Gare D', 'code' => 'D', 'city' => 'D', 'active' => true]);
        $connectionRoute = Route::create([
            'name' => 'B - D',
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stationD->id,
            'estimated_duration_minutes' => 90,
            'active' => true,
        ]);
        foreach ([$stations['b'], $stations['c'], $stationD] as $index => $station) {
            RouteStopOrder::create(['route_id' => $connectionRoute->id, 'station_id' => $station->id, 'stop_index' => $index]);
        }
        RouteFare::create([
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stationD->id,
            'amount' => 3000,
            'is_bidirectional' => true,
            'active' => true,
        ]);
        $inboundTrip->update(['allows_open_connections' => true]);
        $inboundTrip->route->update(['estimated_duration_minutes' => 60]);

        foreach ([[$stations['c']->id, 1], [$stationD->id, 2]] as [$destinationId, $seat]) {
            $this->actingAs($admin)->postJson('/seller/tickets', [
                'trip_id' => $inboundTrip->id,
                'from_station_id' => $stations['a']->id,
                'to_station_id' => $stations['b']->id,
                'final_destination_station_id' => $destinationId,
                'connection_route_id' => $connectionRoute->id,
                'seats' => [$seat],
            ])->assertCreated();
        }
        app(TripTimingService::class)->markDeparted($inboundTrip, now());

        $outboundTrip = Trip::create([
            'route_id' => $connectionRoute->id,
            'vehicle_id' => $inboundTrip->vehicle_id,
            'origin_station_id' => $stations['b']->id,
            'destination_station_id' => $stationD->id,
            'departure_at' => now()->addHours(2),
            'status' => 'scheduled',
            'automatic_connection_allocation' => true,
        ]);
        $assigned = app(AutomaticConnectionAllocator::class)->allocateForTrip($outboundTrip, $admin);

        $this->assertCount(2, $assigned);
        $this->assertEqualsCanonicalizing(
            [$stations['c']->id, $stationD->id],
            $assigned->pluck('destination_station_id')->all()
        );
        $this->assertSame([$connectionRoute->id], $assigned->pluck('route_id')->unique()->values()->all());
        $this->assertTrue($assigned->every(fn ($connection) => data_get($connection->settings, 'seat_allocation.mode') === 'intelligent'));
        $this->assertTrue($assigned->every(fn ($connection) => filled(data_get($connection->settings, 'seat_allocation.reason'))));
    }

    public function test_overlapping_segment_is_rejected_for_same_seat(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture('semi_intelligent');

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['c']->id,
            'seats' => [1],
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['b']->id,
            'to_station_id' => $stations['c']->id,
            'seats' => [1],
        ])->assertStatus(422);
    }

    public function test_reversed_trip_uses_actual_trip_direction(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture('semi_intelligent', reversed: true);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['c']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [2],
        ])->assertStatus(422);
    }

    public function test_cancelling_ticket_keeps_audit_record_and_frees_seat(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        $ticketId = $response->json('ticket_ids.0');

        $this->actingAs($admin)->deleteJson("/seller/tickets/{$ticketId}", [
            'reason' => 'Erreur de saisie',
        ])->assertOk();

        $ticket = Ticket::findOrFail($ticketId);

        $this->assertSame('cancelled', $ticket->status);
        $this->assertNotNull($ticket->cancelled_at);
        $this->assertSame($admin->id, $ticket->cancelled_by);
        $this->assertSame(0, TripSeatOccupancy::where('ticket_id', $ticketId)->count());
    }

    public function test_trip_status_transition_auditing(): void
    {
        Event::fake([SeatMapUpdated::class]);

        [$admin, $trip, $stations] = $this->ticketingFixture();

        // 1. Initial creation status log check
        $this->assertDatabaseHas('trip_status_logs', [
            'trip_id' => $trip->id,
            'status' => 'scheduled',
        ]);

        // 2. Update status via web route (as user/admin)
        $this->actingAs($admin)->patchJson("/seller/trips/{$trip->id}/status", [
            'status' => 'boarding',
        ])->assertRedirect();

        $this->assertSame('boarding', $trip->fresh()->status);
        $this->assertDatabaseHas('trip_status_logs', [
            'trip_id' => $trip->id,
            'status' => 'boarding',
            'changed_by_user_id' => $admin->id,
            'changed_by_crew_member_id' => null,
        ]);

        // 3. Update status as a crew member
        $crewMember = CrewMember::create([
            'name' => 'Moussa Driver',
            'phone' => '+2250700000001',
            'role' => 'driver',
            'pin' => '1234',
            'active' => true,
        ]);

        VehicleCrewAssignment::create([
            'vehicle_id' => $trip->vehicle_id,
            'crew_member_id' => $crewMember->id,
            'role' => 'driver',
            'assigned_from' => now()->subDay(),
        ]);

        auth()->logout();

        $token = $crewMember->createToken('Test Device', ['crew'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/crew/trips/{$trip->id}/status", [
                'status' => 'delayed',
            ])->assertOk();

        $this->assertSame('delayed', $trip->fresh()->status);
        $this->assertDatabaseHas('trip_status_logs', [
            'trip_id' => $trip->id,
            'status' => 'delayed',
            'changed_by_user_id' => null,
            'changed_by_crew_member_id' => $crewMember->id,
        ]);
    }

    public function test_okohi_can_verify_an_issued_ticket(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));

        // 1. Success without key configured
        $this->getJson('/api/okohi/verify?'.http_build_query([
            'ticket_id' => $ticket->ticket_number,
        ]))
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.ticket_id', $ticket->ticket_number)
            ->assertJsonPath('data.amount', $ticket->price);

        // 2. Reject if key is configured but missing/incorrect header
        $settings = TicketSetting::getSettings();
        $settings->update(['okohi_integration_key' => 'secret-test-key']);

        $this->getJson('/api/okohi/verify?'.http_build_query([
            'ticket_id' => $ticket->ticket_number,
        ]))
            ->assertStatus(401);

        $this->withHeader('X-Okohi-Integration-Key', 'wrong-key')
            ->getJson('/api/okohi/verify?'.http_build_query([
                'ticket_id' => $ticket->ticket_number,
            ]))
            ->assertStatus(401);

        // 3. Success with correct key header
        $this->withHeader('X-Okohi-Integration-Key', 'secret-test-key')
            ->getJson('/api/okohi/verify?'.http_build_query([
                'ticket_id' => $ticket->ticket_number,
            ]))
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('data.ticket_id', $ticket->ticket_number);
    }

    public function test_seller_can_lookup_okohi_customer(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $ticket = Ticket::create([
            'ticket_number' => 'TKT-RECENT-001',
            'trip_id' => $trip->id,
            'vehicle_id' => $trip->vehicle_id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'price' => 1000,
            'seller_id' => $admin->id,
            'passenger_name' => 'Jean Client',
            'passenger_phone' => '0102030405',
            'status' => 'sold',
        ]);
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);
        config(['services.okohi.base_url' => 'https://okohi.test']);

        Http::fake([
            'https://okohi.test/api/v1/partner/customers/OKH-123456' => Http::response([
                'customer' => ['name' => 'Jean Client', 'customer_number' => 'OKH-123456'],
                'balance' => ['points_balance' => 150],
                'rewards' => [['id' => 'r-free', 'title' => 'Ticket Gratuit', 'can_grant' => true, 'points_required' => 100]],
                'recent_trips' => [[
                    'id' => 'transaction-1',
                    'ticket_id' => $ticket->ticket_number,
                    'amount' => 1000,
                    'travelled_at' => null,
                ]],
            ], 200),
        ]);

        $this->actingAs($admin)->getJson('/seller/okohi/customers/OKH-123456')
            ->assertOk()
            ->assertJsonPath('customer.name', 'Jean Client')
            ->assertJsonPath('balance.points_balance', 150)
            ->assertJsonPath('recent_trips.0.ticket_id', 'TKT-RECENT-001')
            ->assertJsonPath('recent_trips.0.route_label', 'Gare A → Gare B');
    }

    public function test_okohi_integration_requires_both_url_and_key(): void
    {
        $settings = TicketSetting::getSettings();

        $settings->update([
            'okohi_integration_url' => 'https://okohi.test/scan',
            'okohi_integration_key' => null,
        ]);
        $this->assertFalse($settings->fresh()->hasOkohiIntegration());

        $settings->update(['okohi_integration_key' => 'secret-key']);
        $this->assertTrue($settings->fresh()->hasOkohiIntegration());
    }

    public function test_initiate_okohi_reward_request_creates_temporary_seat_hold(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);
        config(['services.okohi.base_url' => 'https://okohi.test']);

        Http::fake([
            'https://okohi.test/api/v1/partner/customers/OKH-123456/grant-reward' => Http::response([
                'transaction_id' => 'tx-okohi-123',
                'status' => 'pending',
            ], 202),
        ]);

        $response = $this->actingAs($admin)->postJson('/seller/okohi/reward-requests', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'idempotency_key' => 'idemp-123',
        ])->assertStatus(202);

        $this->assertDatabaseHas('okohi_reward_requests', [
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'status' => 'pending',
            'okohi_transaction_id' => 'tx-okohi-123',
        ]);

        // Verify that the seat is now held and blocked in TripSegmentService
        $this->assertDatabaseHas('trip_seat_occupancies', [
            'trip_id' => $trip->id,
            'seat_number' => 1,
        ]);

        $occupied = app(TripSegmentService::class)->occupiedSeatsForSegment($trip, $stations['a']->id, $stations['b']->id);
        $this->assertContains(1, $occupied);
    }

    public function test_cannot_request_already_held_seat(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);
        config(['services.okohi.base_url' => 'https://okohi.test']);

        Http::fake([
            'https://okohi.test/api/v1/partner/customers/OKH-123456/grant-reward' => Http::response([
                'transaction_id' => 'tx-okohi-123',
                'status' => 'pending',
            ], 202),
        ]);

        // First hold
        $this->actingAs($admin)->postJson('/seller/okohi/reward-requests', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'idempotency_key' => 'idemp-123',
        ])->assertStatus(202);

        // Second request on same seat (should fail)
        $this->actingAs($admin)->postJson('/seller/okohi/reward-requests', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-999999',
            'reward_id' => 'r-free',
            'idempotency_key' => 'idemp-456',
        ])->assertStatus(409);
    }

    public function test_okohi_webhook_confirmation_creates_ticket(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(3),
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'confirmed',
                'discount_amount' => 1000,
                'amount_collected' => 0,
            ])->assertOk();

        $request->refresh();
        $this->assertSame('confirmed', $request->status);
        $this->assertNotNull($request->ticket_id);

        $ticket = Ticket::findOrFail($request->ticket_id);
        $this->assertSame('okohi_reward', $ticket->payment_method);
        $this->assertSame(1000, $ticket->gross_amount);
        $this->assertSame(1000, $ticket->discount_amount);
        $this->assertSame(0, $ticket->amount_collected);

        // Verify hold is converted to permanent occupancy
        $this->assertDatabaseHas('trip_seat_occupancies', [
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'ticket_id' => $ticket->id,
            'okohi_reward_request_id' => null,
            'expires_at' => null,
        ]);
    }

    public function test_okohi_webhook_rejection_releases_hold(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
        ]);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(3),
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'rejected',
            ])->assertOk();

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertDatabaseMissing('trip_seat_occupancies', [
            'trip_id' => $trip->id,
            'seat_number' => 1,
        ]);
    }

    public function test_okohi_webhook_approved_pending_cash_and_confirm_cash_flow(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);
        config(['services.okohi.base_url' => 'https://okohi.test']);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-discount',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(3),
        ]);

        $occupancy = TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(3),
        ]);

        // 1. Webhook with amount_collected > 0
        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'approved',
                'reward' => [
                    'benefit_type' => 'percentage_discount',
                    'benefit_value' => 50,
                ],
                'discount_amount' => 500,
                'amount_collected' => 500,
            ])->assertOk()
            ->assertJsonPath('status', 'approved_pending_cash')
            ->assertJsonPath('amount_collected', 500);

        $request->refresh();
        $this->assertSame('approved_pending_cash', $request->status);
        $this->assertNull($request->ticket_id);

        $occupancy->refresh();
        $this->assertGreaterThan(now()->addMinutes(25)->timestamp, $occupancy->expires_at->timestamp);

        // 2. Confirm Cash post by seller
        $response = $this->actingAs($admin)
            ->postJson("/seller/okohi/reward-requests/{$request->id}/confirm-cash");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $request->refresh();
        $this->assertSame('confirmed', $request->status);
        $this->assertNotNull($request->ticket_id);

        $ticket = Ticket::findOrFail($request->ticket_id);
        $this->assertSame(1000, $ticket->price);
        $this->assertSame(500, $ticket->discount_amount);
        $this->assertSame(500, $ticket->amount_collected);

        // 3. Cancel Ticket should trigger ReverseOkohiClaimJob
        Queue::fake();

        $this->actingAs($admin)->deleteJson("/seller/tickets/{$ticket->id}", [
            'reason' => 'Client changed mind',
        ])->assertOk();

        $ticket->refresh();
        $this->assertSame('cancelled', $ticket->status);
        $this->assertSame('refund_pending', $ticket->settings['okohi_refund_status']);

        Queue::assertPushed(CancelOrReverseOkohiClaimJob::class);
    }

    public function test_double_confirm_cash_concurrency(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-discount',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'approved_pending_cash',
            'expires_at' => now()->addMinutes(10),
            'response_payload' => [
                'computed_discount_amount' => 500,
                'computed_amount_collected' => 500,
            ],
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(10),
        ]);

        // First confirmation succeeds
        $this->actingAs($admin)
            ->postJson("/seller/okohi/reward-requests/{$request->id}/confirm-cash")
            ->assertOk();

        // Second concurrent confirmation fails as request status has changed
        $this->actingAs($admin)
            ->postJson("/seller/okohi/reward-requests/{$request->id}/confirm-cash")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Échec de l\'émission du ticket: Cette demande n\'est pas ou plus en attente d\'encaissement.');
    }

    public function test_double_confirm_cash_concurrency_simulated(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-discount',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'approved_pending_cash',
            'expires_at' => now()->addMinutes(10),
            'response_payload' => [
                'computed_discount_amount' => 500,
                'computed_amount_collected' => 500,
            ],
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Register event listener to simulate a concurrent request modifying status during lock inside the transaction
        $dispatcher = OkohiRewardRequest::getEventDispatcher();
        try {
            OkohiRewardRequest::retrieved(function ($model) use ($request) {
                if ($model->id === $request->id && DB::transactionLevel() > 0) {
                    // Simulate another process modifying the status to confirmed
                    DB::table('okohi_reward_requests')
                        ->where('id', $request->id)
                        ->update(['status' => 'confirmed']);
                }
            });

            // The confirmation should fail since the status changed concurrently before check
            $this->actingAs($admin)
                ->postJson("/seller/okohi/reward-requests/{$request->id}/confirm-cash")
                ->assertStatus(422)
                ->assertJsonPath('error', 'Échec de l\'émission du ticket: Cette demande n\'est pas ou plus en attente d\'encaissement.');
        } finally {
            // Restore original event dispatcher to avoid side-effects on other tests
            if ($dispatcher) {
                OkohiRewardRequest::setEventDispatcher($dispatcher);
            }
        }
    }

    public function test_webhook_failing_then_succeeding_retry(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(3),
        ]);

        $occupancy = TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(3),
        ]);

        // 1. Force a retryable error (throw a QueryException simulating deadlock during transaction on first call)
        $callCount = 0;
        $this->partialMock(TripSegmentService::class, function ($mock) use (&$callCount) {
            $mock->shouldReceive('fareAmount')
                ->andReturnUsing(function () use (&$callCount) {
                    $callCount++;
                    if ($callCount === 1) {
                        throw new QueryException(
                            'sqlite',
                            'select * from fares',
                            [],
                            new \PDOException('Lock wait timeout exceeded; try restarting transaction')
                        );
                    }

                    return 1000;
                });
        });

        // First webhook call returns 500 (retryable)
        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'approved',
                'reward' => [
                    'id' => 'r-free',
                    'benefit_type' => 'free_ticket',
                    'benefit_value' => 100,
                ],
            ])->assertStatus(500);

        $request->refresh();
        $this->assertSame('failed', $request->status); // Status is failed

        // 2. Second webhook call (retry) succeeds because we don't mock error anymore
        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'approved',
                'reward' => [
                    'id' => 'r-free',
                    'benefit_type' => 'free_ticket',
                    'benefit_value' => 100,
                ],
            ])->assertOk();

        $request->refresh();
        $this->assertSame('confirmed', $request->status);
        $this->assertNotNull($request->ticket_id);
    }

    public function test_webhook_received_after_expiration_triggers_compensation(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update(['okohi_integration_key' => 'secret-key']);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->subMinutes(1), // Already expired!
        ]);

        Queue::fake();

        // Webhook received after expiration should return 200 (compensation initiated), status failed, and queue reverse job
        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'approved',
                'reward' => [
                    'id' => 'r-free',
                    'benefit_type' => 'free_ticket',
                    'benefit_value' => 100,
                ],
            ])->assertOk()
            ->assertJsonPath('status', 'failed');

        $request->refresh();
        $this->assertSame('failed', $request->status);

        Queue::assertPushed(CancelOrReverseOkohiClaimJob::class);
    }

    public function test_webhook_confirmation_is_idempotent(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'okohi_transaction_id' => 'tx-okohi-123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(3),
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(3),
        ]);

        // Send first webhook
        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'confirmed',
            ])->assertOk();

        $ticketCount = Ticket::where('trip_id', $trip->id)->count();
        $this->assertSame(1, $ticketCount);

        // Send second webhook (should be idempotent and not duplicate)
        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'transaction_id' => 'tx-okohi-123',
                'status' => 'confirmed',
            ])->assertOk();

        $this->assertSame(1, Ticket::where('trip_id', $trip->id)->count());
    }

    public function test_okohi_webhook_new_format_creates_ticket(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);

        $request = OkohiRewardRequest::create([
            'seller_id' => $admin->id,
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'customer_number' => 'OKH-123456',
            'reward_id' => 'r-free',
            'okohi_transaction_id' => '00000000-0000-0000-0000-000000000123',
            'idempotency_key' => 'idemp-123',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(3),
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'okohi_reward_request_id' => $request->id,
            'expires_at' => now()->addMinutes(3),
        ]);

        $this->withHeader('X-Okohi-Integration-Key', 'secret-key')
            ->postJson('/api/okohi/webhook', [
                'claim_id' => '00000000-0000-0000-0000-000000000123',
                'partner_reference' => $request->id,
                'status' => 'approved',
                'reward' => [
                    'id' => 'r-free',
                    'benefit_type' => 'free_ticket',
                    'benefit_value' => 100,
                ],
            ])->assertOk();

        $request->refresh();
        $this->assertSame('confirmed', $request->status);
        $this->assertNotNull($request->ticket_id);

        $ticket = Ticket::findOrFail($request->ticket_id);
        $this->assertSame('okohi_reward', $ticket->payment_method);
        $this->assertSame(1000, $ticket->price); // gross commercial price
        $this->assertSame(1000, $ticket->gross_amount);
        $this->assertSame(1000, $ticket->discount_amount);
        $this->assertSame(0, $ticket->amount_collected);
    }

    public function test_okohi_reversal_on_ticket_cancellation(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();
        $settings = TicketSetting::getSettings();
        $settings->update([
            'okohi_integration_key' => 'secret-key',
            'okohi_integration_url' => 'https://okohi.test',
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'TKT-'.Str::random(8),
            'trip_id' => $trip->id,
            'vehicle_id' => $trip->vehicle_id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seat_number' => 1,
            'passenger_name' => 'Passenger',
            'passenger_phone' => '12345678',
            'price' => 1000,
            'seller_id' => $admin->id,
            'station_id' => $stations['a']->id,
            'qr_code' => 'QR-'.Str::random(12),
            'payment_method' => 'okohi_reward',
            'okohi_customer_number' => 'OKH-123456',
            'okohi_reward_id' => 'r-free',
            'okohi_transaction_id' => 'tx-okohi-123',
            'gross_amount' => 1000,
            'discount_amount' => 1000,
            'amount_collected' => 0,
        ]);

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => 1,
            'ticket_id' => $ticket->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
        ]);

        config(['services.okohi.base_url' => 'https://okohi.test']);
        Http::fake([
            'https://okohi.test/api/v1/partner/reward-claims/tx-okohi-123/reverse' => Http::response(['success' => true]),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/seller/tickets/{$ticket->id}")
            ->assertOk();

        $ticket->refresh();
        $this->assertSame('cancelled', $ticket->status);
        $this->assertDatabaseMissing('trip_seat_occupancies', [
            'ticket_id' => $ticket->id,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://okohi.test/api/v1/partner/reward-claims/tx-okohi-123/reverse'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Okohi-Integration-Key', 'secret-key');
        });
    }

    public function test_printable_qr_uses_okohi_scan_url_when_enabled(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        $ticket = Ticket::findOrFail($response->json('ticket_ids.0'));
        $settings = TicketSetting::getSettings();
        $okohiUrl = 'https://okohi.test/api/v1/scan/{ticket_id}/{amount}/{timestamp}';
        $settings->update([
            'print_qr_code' => true,
            'okohi_integration_url' => $okohiUrl,
            'okohi_integration_key' => 'secret-key',
        ]);
        $settings->refresh();

        $expected = 'https://okohi.test/api/v1/scan/'.$ticket->ticket_number.'/'.$ticket->price.'/'.$ticket->created_at->timestamp;

        $this->assertSame($expected, $ticket->printableQrValue($settings));
    }

    public function test_printable_qr_is_still_printed_when_okohi_is_enabled_even_if_print_qr_code_is_false(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $response = $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        $ticketId = $response->json('ticket_ids.0');
        $settings = TicketSetting::getSettings();
        $settings->update([
            'print_qr_code' => false,
            'okohi_integration_url' => 'https://okohi.test/api/v1/scan/{ticket_id}/{amount}/{timestamp}',
            'okohi_integration_key' => 'secret-key',
        ]);

        $printResponse = $this->actingAs($admin)->get("/tickets/{$ticketId}/print");
        $printResponse->assertOk();
        $printResponse->assertViewHas('qrCode');
        $this->assertNotNull($printResponse->viewData('qrCode'));
    }

    public function test_first_intermediate_destination_suggests_front_zone_on_minibus(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $seatMapService = app(SeatMapService::class);
        $vehicleData = [
            'total_capacity' => 15,
            'door_count' => 1,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+1',
        ];
        $metadata = $seatMapService->calculateMetadata($vehicleData);

        $trip->vehicle->vehicleType->update([
            'seat_count' => $metadata['seat_count'],
            'seat_configuration' => '2+1',
            'door_positions' => $metadata['door_positions'],
            'last_row_seats' => $metadata['last_row_seats'],
            'seat_map' => $seatMapService->generateSeatMap(array_merge($vehicleData, $metadata)),
        ]);
        $trip->vehicle->update(['seat_count' => $metadata['seat_count']]);

        $response = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['b']->id,
            'boarding_station_id' => $stations['a']->id,
            'quantity' => 1,
        ]))->assertOk();

        $suggestedSeat = $response->json('suggested_seats.0.seat_number');
        $suggestionReason = $response->json('suggested_seats.0.reason');

        $this->assertContains($suggestedSeat, range(1, 8));
        $this->assertNotContains($suggestedSeat, range(10, 14));
        $this->assertStringContainsString('Zone Idéale (1)', $suggestionReason);
    }

    public function test_first_intermediate_destination_never_suggests_rear_zone_on_large_coach(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $seatMapService = app(SeatMapService::class);
        $vehicleData = [
            'total_capacity' => 54,
            'door_count' => 2,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+2',
        ];
        $metadata = $seatMapService->calculateMetadata($vehicleData);

        $trip->vehicle->vehicleType->update([
            'seat_count' => $metadata['seat_count'],
            'seat_configuration' => '2+2',
            'door_positions' => $metadata['door_positions'],
            'last_row_seats' => $metadata['last_row_seats'],
            'seat_map' => $seatMapService->generateSeatMap(array_merge($vehicleData, $metadata)),
        ]);
        $trip->vehicle->update(['seat_count' => $metadata['seat_count']]);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [40],
        ])->assertCreated();

        $response = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['b']->id,
            'boarding_station_id' => $stations['a']->id,
            'quantity' => 1,
        ]))->assertOk();

        $suggestedSeat = $response->json('suggested_seats.0.seat_number');

        $this->assertContains($suggestedSeat, range(1, 18));
        $this->assertNotContains($suggestedSeat, range(34, 49));
    }

    public function test_closed_trip_suggests_only_seats_freed_at_boarding_station(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $trip->update(['sales_control' => 'closed']);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertCreated();

        // Seat 3 remains empty, but it should not be suggested for a locked trip.
        $response = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 1,
        ]))->assertOk();

        $this->assertSame(1, $response->json('suggested_seats.0.seat_number'));
    }

    public function test_closed_trip_reuse_drops_a_seat_once_it_is_resold_on_the_same_segment(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture('semi_intelligent');

        $trip->update(['sales_control' => 'closed']);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [3],
        ])->assertCreated();

        $first = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 1,
        ]))->assertOk();

        $this->assertSame(3, $first->json('suggested_seats.0.seat_number'));

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['b']->id,
            'to_station_id' => $stations['c']->id,
            'seats' => [3],
        ])->assertCreated();

        $second = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 1,
        ]))->assertOk();

        $this->assertNotSame(3, $second->json('suggested_seats.0.seat_number'));
    }

    public function test_closed_trip_keeps_the_other_freed_seat_when_one_is_resold(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $seatMapService = app(SeatMapService::class);
        $vehicleData = [
            'total_capacity' => 54,
            'door_count' => 2,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+2',
        ];
        $metadata = $seatMapService->calculateMetadata($vehicleData);

        $trip->vehicle->vehicleType->update([
            'seat_count' => $metadata['seat_count'],
            'seat_configuration' => '2+2',
            'door_positions' => $metadata['door_positions'],
            'last_row_seats' => $metadata['last_row_seats'],
            'seat_map' => $seatMapService->generateSeatMap(array_merge($vehicleData, $metadata)),
        ]);
        $trip->vehicle->update(['seat_count' => $metadata['seat_count']]);
        $trip->update(['sales_control' => 'closed']);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [13, 36],
        ])->assertCreated();

        $first = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 2,
        ]))->assertOk();

        $firstSuggestions = collect($first->json('suggested_seats'))->pluck('seat_number')->all();
        $this->assertContains(13, $firstSuggestions);
        $this->assertContains(36, $firstSuggestions);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['b']->id,
            'to_station_id' => $stations['c']->id,
            'seats' => [13],
        ])->assertCreated();

        $second = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 2,
        ]))->assertOk();

        $secondSuggestions = collect($second->json('suggested_seats'))->pluck('seat_number')->all();
        $this->assertContains(36, $secondSuggestions);
        $this->assertNotContains(13, $secondSuggestions);
    }

    public function test_departed_trip_suggestions_keep_all_unsold_and_freed_seats(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $seatMapService = app(SeatMapService::class);
        $vehicleData = [
            'total_capacity' => 54,
            'door_count' => 2,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+2',
        ];
        $metadata = $seatMapService->calculateMetadata($vehicleData);

        $trip->vehicle->vehicleType->update([
            'seat_count' => $metadata['seat_count'],
            'seat_configuration' => '2+2',
            'door_positions' => $metadata['door_positions'],
            'last_row_seats' => $metadata['last_row_seats'],
            'seat_map' => $seatMapService->generateSeatMap(array_merge($vehicleData, $metadata)),
        ]);
        $trip->vehicle->update(['seat_count' => $metadata['seat_count']]);
        $trip->update(['sales_control' => 'closed', 'status' => 'boarding']);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [13, 36],
        ])->assertCreated();
        $trip->update(['status' => 'departed']);

        $first = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 50,
        ]))->assertOk();

        $firstSuggestions = collect($first->json('suggested_seats'))->pluck('seat_number')->all();
        $this->assertContains(13, $firstSuggestions);
        $this->assertContains(36, $firstSuggestions);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['b']->id,
            'to_station_id' => $stations['c']->id,
            'seats' => [13],
        ])->assertCreated();

        $second = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/suggest-seats?".http_build_query([
            'destination_station_id' => $stations['c']->id,
            'boarding_station_id' => $stations['b']->id,
            'quantity' => 50,
        ]))->assertOk();

        $secondSuggestions = collect($second->json('suggested_seats'))->pluck('seat_number')->all();
        $this->assertContains(36, $secondSuggestions);
        $this->assertNotContains(13, $secondSuggestions);
    }

    public function test_non_selling_stop_reassigns_freed_seats_to_previous_selling_station(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        $stations['b']->update(['can_sell_tickets' => false]);
        $trip->update(['sales_control' => 'closed', 'status' => 'boarding']);

        $this->actingAs($admin)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [3],
        ])->assertCreated();
        $trip->update(['status' => 'departed']);

        $seatMap = $this->actingAs($admin)->getJson("/seller/trips/{$trip->id}/seat-map?".http_build_query([
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['c']->id,
        ]))->assertOk();

        $sellableA = collect($seatMap->json("sellable_seats_by_station.{$stations['a']->id}"))->map(fn ($seat) => (int) $seat)->all();
        $sellableB = collect($seatMap->json("sellable_seats_by_station.{$stations['b']->id}"))->map(fn ($seat) => (int) $seat)->all();

        $this->assertContains(3, $sellableA);
        $this->assertNotContains(3, $sellableB);
    }

    public function test_route_stop_management_syncs_terminal_stations(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'active' => true,
        ]);

        $stationA = Station::create(['name' => 'Gare A', 'code' => 'RA', 'city' => 'A', 'active' => true]);
        $stationB = Station::create(['name' => 'Gare B', 'code' => 'RB', 'city' => 'B', 'active' => true]);
        $route = Route::create([
            'name' => 'A - B',
            'active' => true,
        ]);

        $this->actingAs($admin)->post("/admin/routes/{$route->id}/stops", [
            'station_id' => $stationA->id,
            'stop_index' => 0,
        ])->assertRedirect();

        $this->actingAs($admin)->post("/admin/routes/{$route->id}/stops", [
            'station_id' => $stationB->id,
            'stop_index' => 1,
        ])->assertRedirect();

        $route->refresh();

        $this->assertSame($stationA->id, $route->origin_station_id);
        $this->assertSame($stationB->id, $route->destination_station_id);
    }

    public function test_route_stop_management_still_accepts_legacy_stop_id_payload(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'active' => true,
        ]);

        $station = Station::create(['name' => 'Gare Legacy', 'code' => 'RL', 'city' => 'A', 'active' => true]);
        $route = Route::create([
            'name' => 'Legacy Route',
            'active' => true,
        ]);

        $this->actingAs($admin)->post("/admin/routes/{$route->id}/stops", [
            'stop_id' => $station->id,
            'stop_index' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('route_stop_orders', [
            'route_id' => $route->id,
            'station_id' => $station->id,
            'stop_index' => 0,
        ]);
    }

    public function test_trip_can_be_created_without_vehicle_if_replicable(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'active' => true,
        ]);

        $stationA = Station::create(['name' => 'Gare A', 'code' => 'RA', 'city' => 'A', 'active' => true]);
        $stationB = Station::create(['name' => 'Gare B', 'code' => 'RB', 'city' => 'B', 'active' => true]);
        $route = Route::create([
            'name' => 'A - B',
            'active' => true,
            'origin_station_id' => $stationA->id,
            'destination_station_id' => $stationB->id,
        ]);

        Event::fake([
            TripCreated::class,
        ]);

        // Creating a trip without vehicle_id should be successful
        $this->actingAs($admin)->post('/admin/trips', [
            'route_id' => $route->id,
            'vehicle_id' => '',
            'departure_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
            'is_replicable' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('trips', [
            'route_id' => $route->id,
            'vehicle_id' => null,
            'is_replicable' => true,
        ]);
    }

    public function test_trip_replication_command_replicates_marked_trips_to_next_day(): void
    {
        $stationA = Station::create(['name' => 'Gare A', 'code' => 'RA', 'city' => 'A', 'active' => true]);
        $stationB = Station::create(['name' => 'Gare B', 'code' => 'RB', 'city' => 'B', 'active' => true]);
        $route = Route::create([
            'name' => 'A - B',
            'active' => true,
            'origin_station_id' => $stationA->id,
            'destination_station_id' => $stationB->id,
        ]);

        $departureToday = now()->setTime(14, 30, 0); // Scheduled for 14:30 today

        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => null,
            'departure_at' => $departureToday,
            'status' => 'scheduled',
            'is_replicable' => true,
        ]);

        // Run the Artisan command
        $this->artisan('trips:replicate')
            ->expectsOutput('Running replication in single-tenant/local context.')
            ->expectsOutput('Found 1 replicable trips for today.')
            ->expectsOutput('Successfully replicated 1 trips.')
            ->assertExitCode(0);

        // Verify that a new trip has been created for tomorrow at 14:30 with vehicle_id = null and is_replicable = true
        $tomorrowDeparture = $departureToday->copy()->addDay();
        $this->assertDatabaseHas('trips', [
            'route_id' => $route->id,
            'vehicle_id' => null,
            'departure_at' => $tomorrowDeparture,
            'status' => 'scheduled',
            'is_replicable' => true,
        ]);
    }

    public function test_trip_code_generation_sequential_suffix(): void
    {
        $stationA = Station::create(['name' => 'Gare A', 'code' => 'RA', 'city' => 'A', 'active' => true]);
        $stationB = Station::create(['name' => 'Gare B', 'code' => 'RB', 'city' => 'B', 'active' => true]);
        $route = Route::create([
            'name' => 'A - B',
            'active' => true,
            'origin_station_id' => $stationA->id,
            'destination_station_id' => $stationB->id,
        ]);

        $departureTime = now()->setTime(14, 30, 0);

        $trip1 = Trip::create([
            'route_id' => $route->id,
            'departure_at' => $departureTime,
            'status' => 'scheduled',
        ]);
        $this->assertSame('RA-RB-1430', $trip1->code);

        $trip2 = Trip::create([
            'route_id' => $route->id,
            'departure_at' => $departureTime,
            'status' => 'scheduled',
        ]);
        $this->assertSame('RA-RB-1430-2', $trip2->code);

        $trip3 = Trip::create([
            'route_id' => $route->id,
            'departure_at' => $departureTime,
            'status' => 'scheduled',
        ]);
        $this->assertSame('RA-RB-1430-3', $trip3->code);
    }

    public function test_seller_route_assignments_restrictions(): void
    {
        [$admin, $trip, $stations] = $this->ticketingFixture();

        // Create a second route serving the same origin station
        $route2 = Route::create([
            'name' => 'A - B (Alternate)',
            'origin_station_id' => $stations['a']->id,
            'destination_station_id' => $stations['b']->id,
            'active' => true,
        ]);

        $trip2 = Trip::create([
            'route_id' => $route2->id,
            'vehicle_id' => $trip->vehicle_id,
            'origin_station_id' => $stations['a']->id,
            'destination_station_id' => $stations['b']->id,
            'departure_at' => now()->addHour(),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment',
            'sales_control' => 'open',
        ]);

        // Create a seller
        $seller = User::factory()->create([
            'role' => 'seller',
            'active' => true,
        ]);

        // Assign the seller to station A
        UserStationAssignment::create([
            'user_id' => $seller->id,
            'station_id' => $stations['a']->id,
            'active' => true,
        ]);

        // 1. Without route assignments, both routes are accessible
        $accessibleRouteIds = $seller->accessibleRoutesQuery()->pluck('id')->toArray();
        $this->assertContains($trip->route_id, $accessibleRouteIds);
        $this->assertContains($route2->id, $accessibleRouteIds);

        // 2. Assign the seller to only the first route for station A
        UserRouteAssignment::create([
            'user_id' => $seller->id,
            'station_id' => $stations['a']->id,
            'route_id' => $trip->route_id,
            'active' => true,
        ]);

        // Clear cached relations or query again
        $accessibleRouteIds = $seller->fresh()->accessibleRoutesQuery()->pluck('id')->toArray();
        $this->assertContains($trip->route_id, $accessibleRouteIds);
        $this->assertNotContains($route2->id, $accessibleRouteIds);

        // 3. Trying to purchase ticket for trip2 (on route2) should be rejected for the seller
        $this->actingAs($seller)->postJson('/seller/tickets', [
            'trip_id' => $trip2->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertStatus(403);

        // 4. Purchasing ticket for trip1 (on route1) should succeed
        $this->actingAs($seller)->postJson('/seller/tickets', [
            'trip_id' => $trip->id,
            'from_station_id' => $stations['a']->id,
            'to_station_id' => $stations['b']->id,
            'seats' => [1],
        ])->assertStatus(201);
    }

    private function ticketingFixture(string $bookingType = 'seat_assignment', bool $reversed = false): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'active' => true,
        ]);

        $stations = [
            'a' => Station::create(['name' => 'Gare A', 'code' => 'A', 'city' => 'A', 'active' => true]),
            'b' => Station::create(['name' => 'Gare B', 'code' => 'B', 'city' => 'B', 'active' => true]),
            'c' => Station::create(['name' => 'Gare C', 'code' => 'C', 'city' => 'C', 'active' => true]),
        ];

        $route = Route::create([
            'name' => 'A - C',
            'origin_station_id' => $stations['a']->id,
            'destination_station_id' => $stations['c']->id,
            'active' => true,
        ]);

        foreach (['a', 'b', 'c'] as $index => $key) {
            RouteStopOrder::create([
                'route_id' => $route->id,
                'station_id' => $stations[$key]->id,
                'stop_index' => $index,
            ]);
        }

        foreach ([['a', 'b', 1000], ['b', 'c', 1000], ['a', 'c', 2000], ['c', 'b', 1000]] as [$from, $to, $amount]) {
            RouteFare::create([
                'from_station_id' => $stations[$from]->id,
                'to_station_id' => $stations[$to]->id,
                'amount' => $amount,
                'is_bidirectional' => true,
                'active' => true,
            ]);
        }

        $vehicleType = VehicleType::create([
            'name' => 'Mini',
            'seat_count' => 4,
            'seat_configuration' => '2+2',
            'door_positions' => [0],
            'last_row_seats' => 2,
            'active' => true,
        ]);

        $vehicle = Vehicle::create([
            'identifier' => 'BUS-1',
            'maker' => 'Toyota',
            'vehicle_type_id' => $vehicleType->id,
            'seat_count' => 4,
            'active' => true,
        ]);

        $trip = Trip::create([
            'route_id' => $route->id,
            'vehicle_id' => $vehicle->id,
            'origin_station_id' => $reversed ? $stations['c']->id : $stations['a']->id,
            'destination_station_id' => $reversed ? $stations['a']->id : $stations['c']->id,
            'departure_at' => now()->addHour(),
            'status' => 'scheduled',
            'booking_type' => $bookingType,
            'sales_control' => 'open',
        ]);

        return [$admin, $trip, $stations];
    }

    private function ensureTenantTicketingTablesExist(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('telephone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('seller')->index();
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

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
                $table->string('role')->index();
                $table->dateTime('assigned_from');
                $table->dateTime('assigned_to')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trips')) {
            Schema::create('trips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code')->nullable();
                $table->uuid('route_id')->index();
                $table->uuid('origin_station_id')->nullable();
                $table->uuid('destination_station_id')->nullable();
                $table->uuid('vehicle_id')->nullable()->index();
                $table->dateTime('departure_at');
                $table->timestamp('planned_arrival_at')->nullable();
                $table->timestamp('actual_departed_at')->nullable();
                $table->timestamp('estimated_arrival_at')->nullable();
                $table->string('status')->default('scheduled');
                $table->string('booking_type')->default('seat_assignment');
                $table->string('sales_control')->default('closed');
                $table->boolean('allows_open_connections')->default(false);
                $table->boolean('automatic_connection_allocation')->nullable();
                $table->boolean('is_replicable')->default(false);
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
                $table->uuid('seller_id')->index();
                $table->uuid('station_id')->nullable()->index();
                $table->string('status')->default('issued')->index();
                $table->unsignedTinyInteger('boarding_group')->nullable();
                $table->json('qr_payload')->nullable();
                $table->string('qr_code')->nullable();
                $table->json('settings')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->uuid('cancelled_by')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->string('payment_method')->default('cash');
                $table->string('okohi_customer_number')->nullable();
                $table->string('okohi_reward_id')->nullable();
                $table->string('okohi_transaction_id')->nullable();
                $table->integer('gross_amount')->nullable();
                $table->integer('discount_amount')->nullable();
                $table->integer('amount_collected')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('okohi_reward_requests')) {
            Schema::create('okohi_reward_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('seller_id');
                $table->uuid('trip_id');
                $table->uuid('from_station_id');
                $table->uuid('to_station_id');
                $table->integer('seat_number');
                $table->string('customer_number');
                $table->string('reward_id');
                $table->string('okohi_transaction_id')->nullable()->index();
                $table->string('idempotency_key')->unique();
                $table->string('status')->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->uuid('ticket_id')->nullable();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('last_error')->nullable();
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
                $table->uuid('okohi_reward_request_id')->nullable()->index();
                $table->timestamp('expires_at')->nullable();
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

        if (! Schema::hasTable('ticket_connection_assignments')) {
            Schema::create('ticket_connection_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('ticket_connection_id')->index();
                $table->uuid('from_trip_id')->nullable()->index();
                $table->uuid('to_trip_id')->nullable()->index();
                $table->unsignedInteger('from_seat_number')->nullable();
                $table->unsignedInteger('to_seat_number')->nullable();
                $table->string('action')->index();
                $table->string('reason')->nullable();
                $table->uuid('performed_by')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
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

        if (! Schema::hasTable('operational_settings')) {
            Schema::create('operational_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('automatic_connection_allocation')->default(false);
                $table->unsignedInteger('connection_transfer_buffer_minutes')->default(15);
                $table->json('settings')->nullable();
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

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('tokenable_type');
                $table->uuid('tokenable_id');
                $table->index(['tokenable_type', 'tokenable_id']);
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_station_assignments')) {
            Schema::create('user_station_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->uuid('station_id')->index();
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_route_assignments')) {
            Schema::create('user_route_assignments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->uuid('route_id')->index();
                $table->uuid('station_id')->nullable()->index();
                $table->boolean('active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }
    }
}
