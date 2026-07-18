<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trips\CrewTripAccessPolicy;
use App\Domain\Trips\CrewTripVisibility;
use App\Domain\Trips\InvalidTripTransition;
use App\Domain\Trips\TripStateMachine;
use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Services\SeatMapService;
use App\Services\TripSegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CrewTripController extends Controller
{
    public function index(Request $request)
    {
        $crewMember = $request->user();

        $visibility = app(CrewTripVisibility::class);
        $query = Trip::with([
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
            'tickets.fromStation',
            'tickets.toStation',
        ])
            ->withCount(['tickets as tickets_count' => fn ($query) => $query->where('status', '!=', 'cancelled')]);

        $trips = $visibility->filter($visibility->apply($query, $crewMember)->get(), $crewMember)
            ->sortBy(function (Trip $trip) {
                $statusOrder = match ($trip->status) {
                    'boarding' => 0,
                    'delayed' => 1,
                    'departed' => 2,
                    'scheduled' => 3,
                    default => 4,
                };

                return sprintf('%d-%s', $statusOrder, $trip->departure_at?->toDateTimeString());
            })
            ->map(fn (Trip $trip) => $this->tripPayload($trip))
            ->values();

        return response()->json([
            'trips' => $trips,
        ]);
    }

    public function show(Request $request, Trip $trip, TripSegmentService $segments, SeatMapService $seatMaps)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $trip->load([
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
            'tickets.fromStation',
            'tickets.toStation',
            'tickets.boardedBy',
            'tripSeatOccupancies.ticket.fromStation',
            'tripSeatOccupancies.ticket.toStation',
            'assignedConnections.ticket.finalDestinationStation',
            'assignedConnections.transferStation',
            'assignedConnections.destinationStation',
        ]);

        $connectionTickets = $trip->assignedConnections
            ->whereIn('status', ['assigned', 'boarded', 'completed'])
            ->map(function ($connection) {
                $ticket = $connection->ticket;
                $ticket->seat_number = $connection->seat_number;
                $ticket->boarded_at = $connection->boarded_at;
                $ticket->setRelation('fromStation', $connection->transferStation);
                $ticket->setRelation('toStation', $connection->destinationStation);
                $ticket->setRelation('connection', $connection);

                return $ticket;
            });

        $vehicleType = $trip->vehicle?->vehicleType;
        $seatMap = [];
        if ($vehicleType) {
            $seatMap = $seatMaps->ensureGrid($vehicleType->seat_map ?? [], [
                'seat_count' => $vehicleType->seat_count ?? $trip->total_seats,
                'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
                'door_positions' => $vehicleType->door_positions ?? [],
                'last_row_seats' => $vehicleType->last_row_seats ?? 0,
            ]);
        }

        return response()->json([
            'trip' => $this->tripPayload($trip, $seatMap),
            'manifest' => $trip->tickets->concat($connectionTickets)->map(fn (Ticket $ticket) => $this->ticketPayload($ticket, $trip->id))->values(),
            'occupancy' => [
                'total_seats' => $trip->total_seats,
                'available_seats' => $trip->available_seats,
                'occupied_seats_count' => $trip->occupied_seats_count,
                'sold_tickets_count' => $trip->sold_tickets_count,
            ],
            'sellable_seats_by_station' => $trip->route
                ? collect($trip->route->routeStopOrders)->mapWithKeys(fn ($stop) => [
                    $stop->station_id => $segments->sellableSeatsForStation($trip, $stop->station_id),
                ])->all()
                : [],
        ]);
    }

    public function updateStatus(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:scheduled,boarding,departed,arrived,cancelled,delayed,embarquement,parti,en_route,arrive,arrivé,retardé,retarde'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $trip = app(TripStateMachine::class)->transition(
                $trip,
                $validated['status'],
                $request->user(),
                'crew_api',
                $validated['reason'] ?? null,
            );
        } catch (InvalidTripTransition $exception) {
            return response()->json([
                'code' => 'invalid_trip_transition',
                'message' => $exception->getMessage(),
            ], 422);
        }

        try {
            event(new SeatMapUpdated(
                $trip->fresh([
                    'route.routeStopOrders.station',
                    'originStation',
                    'destinationStation',
                    'vehicle.vehicleType',
                ]),
                [],
                'trip.status_updated',
                $trip->origin_station_id
            ));
        } catch (\Exception $e) {
            Log::warning('Échec broadcast SeatMapUpdated: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Statut du voyage mis à jour.',
            'trip' => $this->tripPayload($trip->fresh([
                'route.originStation',
                'route.destinationStation',
                'route.routeStopOrders.station',
                'vehicle.vehicleType',
            ])),
        ]);
    }

    private function tripPayload(Trip $trip, array $seatMap = []): array
    {
        $soldSeatsByStation = $trip->tickets
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('from_station_id')
            ->countBy('from_station_id');

        $globalCrewSales = TicketSetting::getSettings()->allowsCrewSales();
        $tripCrewSales = (bool) data_get($trip->settings, 'allow_crew_sales', false);
        $salesStatusAllowed = in_array($trip->status, ['boarding', 'departed'], true);
        $canSellOnBoard = $globalCrewSales && $tripCrewSales && $salesStatusAllowed;
        $salesDenialReason = match (true) {
            ! $globalCrewSales => 'global_crew_sales_disabled',
            ! $tripCrewSales => 'trip_crew_sales_disabled',
            ! $salesStatusAllowed => 'crew_sales_wrong_status',
            default => null,
        };

        return [
            'id' => $trip->id,
            'code' => $trip->code,
            'display_name' => $trip->display_name,
            'departure_at' => $trip->departure_at?->toIso8601String(),
            'planned_arrival_at' => $trip->planned_arrival_at?->toIso8601String(),
            'actual_departed_at' => $trip->actual_departed_at?->toIso8601String(),
            'estimated_arrival_at' => $trip->estimated_arrival_at?->toIso8601String(),
            'estimated_duration_minutes' => $trip->route?->estimated_duration_minutes,
            'status' => $trip->status,
            'sales_control' => $trip->sales_control,
            'settings' => $trip->settings,
            'permissions' => [
                'can_sell_on_board' => $canSellOnBoard,
                'can_board' => ! in_array($trip->status, ['arrived', 'cancelled'], true),
                'can_change_status' => ! in_array($trip->status, ['arrived', 'cancelled'], true),
                'sales_denial_reason' => $salesDenialReason,
            ],
            'driver' => $trip->driver ? [
                'id' => $trip->driver->id,
                'name' => $trip->driver->name,
            ] : null,
            'assistant' => $trip->assistant ? [
                'id' => $trip->assistant->id,
                'name' => $trip->assistant->name,
            ] : null,
            'seat_map' => $seatMap,
            'origin_station' => $trip->originStation ? [
                'id' => $trip->originStation->id,
                'name' => $trip->originStation->name,
            ] : null,
            'destination_station' => $trip->destinationStation ? [
                'id' => $trip->destinationStation->id,
                'name' => $trip->destinationStation->name,
            ] : null,
            'vehicle' => $trip->vehicle ? [
                'id' => $trip->vehicle->id,
                'identifier' => $trip->vehicle->identifier,
                'seat_count' => $trip->total_seats,
            ] : null,
            'route' => $trip->route ? [
                'id' => $trip->route->id,
                'name' => $trip->route->name,
                'route_stop_orders' => $trip->route->routeStopOrders->map(fn ($stop) => [
                    'id' => $stop->id,
                    'station_id' => $stop->station_id,
                    'stop_index' => $stop->stop_index,
                    'sold_seats_count' => $soldSeatsByStation->get($stop->station_id, 0),
                    'station' => $stop->station ? [
                        'id' => $stop->station->id,
                        'name' => $stop->station->name,
                    ] : null,
                ])->values()->all(),
            ] : null,
            'total_seats' => $trip->total_seats,
            'available_seats' => $trip->available_seats,
            'occupied_seats_count' => $trip->occupied_seats_count,
            'sold_tickets_count' => $trip->sold_tickets_count,
            'tickets_count' => $trip->tickets_count ?? null,
            'allows_open_connections' => $trip->allows_open_connections,
        ];
    }

    private function ticketPayload(Ticket $ticket, ?string $contextTripId = null): array
    {
        $connection = $ticket->connection;
        $isConnectionSegment = $connection && $contextTripId
            && $connection->trip_id === $contextTripId
            && $ticket->trip_id !== $contextTripId;

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'seat_number' => $ticket->seat_number,
            'passenger_name' => $ticket->passenger_name,
            'passenger_phone' => $ticket->passenger_phone,
            'status' => $ticket->status,
            'boarded_at' => $ticket->boarded_at?->toIso8601String(),
            'from_station' => $ticket->fromStation ? [
                'id' => $ticket->fromStation->id,
                'name' => $ticket->fromStation->name,
            ] : null,
            'to_station' => $ticket->toStation ? [
                'id' => $ticket->toStation->id,
                'name' => $ticket->toStation->name,
            ] : null,
            'boarded_by' => $ticket->boardedBy ? [
                'id' => $ticket->boardedBy->id,
                'name' => $ticket->boardedBy->name,
            ] : null,
            'final_destination' => $ticket->finalDestinationStation ? [
                'id' => $ticket->finalDestinationStation->id,
                'name' => $ticket->finalDestinationStation->name,
            ] : null,
            'is_connection_segment' => $isConnectionSegment,
            'connection' => $connection ? [
                'status' => $connection->status,
                'trip_id' => $connection->trip_id,
                'seat_number' => $connection->seat_number,
                'transfer_station_id' => $connection->transfer_station_id,
                'destination_station_id' => $connection->destination_station_id,
                'has_conflict' => $connection->hasConflict(),
            ] : null,
        ];
    }

    private function normalizeTripStatus(string $status): string
    {
        return match ($status) {
            'embarquement' => 'boarding',
            'parti' => 'departed',
            'en_route' => 'departed',
            'arrive', 'arrivé' => 'arrived',
            'retardé', 'retarde' => 'delayed',
            default => $status,
        };
    }

    private function assertCrewVehicleAccess(Request $request, Trip $trip): void
    {
        abort_unless(
            app(CrewTripAccessPolicy::class)->canAccess($request->user(), $trip),
            403,
            'Ce voyage ne correspond pas à vos affectations.',
        );
    }
}
