<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStopOrder;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

        $this->getJson('/api/okohi/verify?'.http_build_query([
            'ticket_id' => $ticket->ticket_number,
        ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ticket_id', $ticket->ticket_number)
            ->assertJsonPath('data.amount', $ticket->price);
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
        $settings->update([
            'print_qr_code' => true,
            'okohi_enabled' => true,
            'okohi_host' => 'https://okohi.test',
            'okohi_company_id' => '11111111-1111-1111-1111-111111111111',
            'okohi_loyalty_type' => 'points',
            'okohi_integration_key' => 'okohi-key',
        ]);

        $this->assertSame(
            'https://okohi.test/api/v1/scan/11111111-1111-1111-1111-111111111111/points/okohi-key/'.$ticket->ticket_number.'/'.$ticket->price.'/'.$ticket->created_at->timestamp,
            $ticket->printableQrValue($settings)
        );
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
            'okohi_enabled' => true,
            'okohi_host' => 'https://okohi.test',
            'okohi_company_id' => '11111111-1111-1111-1111-111111111111',
            'okohi_loyalty_type' => 'points',
            'okohi_integration_key' => 'okohi-key',
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

        if (! Schema::hasTable('trips')) {
            Schema::create('trips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('route_id')->index();
                $table->uuid('origin_station_id')->nullable();
                $table->uuid('destination_station_id')->nullable();
                $table->uuid('vehicle_id')->index();
                $table->dateTime('departure_at');
                $table->string('status')->default('scheduled');
                $table->string('booking_type')->default('seat_assignment');
                $table->string('sales_control')->default('closed');
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
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('trip_seat_occupancies')) {
            Schema::create('trip_seat_occupancies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('trip_id')->index();
                $table->unsignedInteger('seat_number');
                $table->uuid('ticket_id')->nullable()->index();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ticket_settings')) {
            Schema::create('ticket_settings', function (Blueprint $table) {
                $table->id();
                $table->string('company_name')->default('TSR CI');
                $table->json('phone_numbers')->nullable();
                $table->string('cc_label')->nullable();
                $table->json('footer_messages')->nullable();
                $table->text('baggage_policy_message')->nullable();
                $table->json('qr_code_base_url')->nullable();
                $table->boolean('print_qr_code')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }
    }
}
