<?php

namespace Tests\Feature;

use App\Models\Route;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserStationAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\InteractsWithTenantTicketing;

class TicketPolicyTest extends TestCase
{
    use InteractsWithTenantTicketing, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTenantTicketingTablesExist();
    }

    public function test_seller_can_print_their_own_ticket(): void
    {
        [$seller, $ticket] = $this->createSellerAndTicket();

        $this->actingAs($seller)
            ->get(route('tickets.print', ['ticket' => $ticket->id]))
            ->assertOk();
    }

    public function test_seller_cannot_print_ticket_from_unassigned_station(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);

        $otherStation = Station::create(['name' => 'Gare Autre', 'code' => 'AUT', 'city' => 'Autre', 'active' => true]);
        $ticket = $this->createTicket([
            'from_station_id' => $otherStation->id,
            'seller_id' => User::factory()->create(['role' => 'seller'])->id,
        ]);

        $this->actingAs($seller)
            ->get(route('tickets.print', ['ticket' => $ticket->id]))
            ->assertForbidden();
    }

    public function test_admin_can_print_any_ticket(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $ticket = $this->createTicket();

        $this->actingAs($admin)
            ->get(route('tickets.print', ['ticket' => $ticket->id]))
            ->assertOk();
    }

    public function test_seller_cannot_print_multiple_tickets_if_one_is_out_of_scope(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);
        $station = Station::create(['name' => 'Gare A', 'code' => 'GA', 'city' => 'Ville A', 'active' => true]);
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $station->id, 'active' => true]);

        $ownTicket = $this->createTicket(['seller_id' => $seller->id, 'from_station_id' => $station->id]);

        $otherStation = Station::create(['name' => 'Gare B', 'code' => 'GB', 'city' => 'Ville B', 'active' => true]);
        $otherTicket = $this->createTicket([
            'seller_id' => User::factory()->create(['role' => 'seller'])->id,
            'from_station_id' => $otherStation->id,
        ]);

        $this->actingAs($seller)
            ->post(route('tickets.print-multiple'), [
                'ticket_ids' => [$ownTicket->id, $otherTicket->id],
            ])
            ->assertForbidden();
    }

    public function test_seller_cannot_cancel_ticket_belonging_to_another_seller(): void
    {
        $seller1 = User::factory()->create(['role' => 'seller', 'active' => true]);
        $seller2 = User::factory()->create(['role' => 'seller', 'active' => true]);

        $ticket = $this->createTicket(['seller_id' => $seller1->id]);

        $this->actingAs($seller2)
            ->patchJson(route('api.tickets.cancel', ['ticket' => $ticket->id]))
            ->assertForbidden();
    }

    private function createSellerAndTicket(): array
    {
        $seller = User::factory()->create(['role' => 'seller', 'active' => true]);
        $station = Station::create(['name' => 'Gare Test', 'code' => 'TST', 'city' => 'Test', 'active' => true]);
        UserStationAssignment::create(['user_id' => $seller->id, 'station_id' => $station->id, 'active' => true]);

        $ticket = $this->createTicket([
            'seller_id' => $seller->id,
            'from_station_id' => $station->id,
        ]);

        return [$seller, $ticket];
    }

    private function createTicket(array $attributes = []): Ticket
    {
        $stationA = Station::create(['name' => 'Gare Default A '.rand(100, 999), 'code' => 'DA'.rand(1000, 9999), 'city' => 'A', 'active' => true]);
        $stationB = Station::create(['name' => 'Gare Default B '.rand(100, 999), 'code' => 'DB'.rand(1000, 9999), 'city' => 'B', 'active' => true]);
        $route = Route::create(['name' => 'A-B', 'origin_station_id' => $stationA->id, 'destination_station_id' => $stationB->id, 'active' => true]);
        $trip = Trip::create([
            'route_id' => $route->id,
            'origin_station_id' => $stationA->id,
            'destination_station_id' => $stationB->id,
            'departure_at' => now()->addHour(),
        ]);

        return Ticket::create(array_merge([
            'ticket_number' => 'TKT-'.strtoupper(Str::random(8)),
            'trip_id' => $trip->id,
            'from_station_id' => $stationA->id,
            'to_station_id' => $stationB->id,
            'price' => 5000,
            'status' => 'issued',
        ], $attributes));
    }
}
