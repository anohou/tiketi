<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrewStatusReport;
use App\Models\Route;
use App\Models\Ticket;
use App\Models\TicketConnection;
use App\Models\Trip;
use App\Services\OptimisationService;
use App\Services\SeatMapService;
use App\Services\TripSegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TripController extends Controller
{
    protected $optimisationService;

    public function __construct(OptimisationService $optimisationService)
    {
        $this->optimisationService = $optimisationService;
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $query = Trip::withCount(['tickets as tickets_count' => function ($q) {
            $q->where('status', 'issued');
        }])
            ->with([
                'route.originStation',
                'route.destinationStation',
                'route.routeStopOrders.station',
                'vehicle.vehicleType',
            ])
            ->where('departure_at', '>=', now())
            ->upcomingFirst();

        if ($user && in_array($user->role, ['seller', 'supervisor'], true)) {
            $query->whereIn('route_id', $user->accessibleRoutesQuery()->pluck('id'));
        }

        $trips = $query->get();

        if ($user && $user->role !== 'admin') {
            $assignedStationIds = $user->getActiveStationIds();
            $segmentService = app(TripSegmentService::class);
            $trips = $trips->filter(function ($trip) use ($assignedStationIds, $segmentService) {
                $servedStationIds = array_keys($segmentService->stationIndices($trip));

                return ! empty(array_intersect($assignedStationIds, $servedStationIds));
            })->values();
        }

        return $trips;
    }

    public function byRouteAndDate(string $routeId, string $date)
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        Route::whereKey($routeId)->where('active', true)->firstOrFail();
        $requestedDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

        $trips = Trip::with([
            'route.originStation',
            'route.destinationStation',
            'vehicle.vehicleType',
        ])
            ->where('route_id', $routeId)
            ->whereDate('departure_at', $requestedDate)
            ->whereNotIn('status', ['cancelled'])
            ->upcomingFirst()
            ->get()
            ->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'code' => $trip->code,
                'departure_at' => $trip->departure_at?->toIso8601String(),
                'status' => $trip->status,
                'sales_control' => $trip->sales_control,
                'booking_type' => $trip->booking_type,
                'route' => [
                    'id' => $trip->route->id,
                    'name' => $trip->route->name,
                    'origin' => [
                        'id' => $trip->route->originStation->id,
                        'name' => $trip->route->originStation->name,
                        'city' => $trip->route->originStation->city,
                    ],
                    'destination' => [
                        'id' => $trip->route->destinationStation->id,
                        'name' => $trip->route->destinationStation->name,
                        'city' => $trip->route->destinationStation->city,
                    ],
                ],
                'vehicle' => $trip->vehicle?->vehicleType ? [
                    'type' => $trip->vehicle->vehicleType->name,
                    'capacity' => $trip->total_seats,
                ] : null,
            ]);

        return response()->json($trips)->setPublic()->setMaxAge(30);
    }

    public function show(string $id)
    {
        return Trip::with([
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
            'originStation',
            'destinationStation',
        ])
            ->withCount(['tickets as tickets_count' => function ($q) {
                $q->where('status', 'issued');
            }])
            ->findOrFail($id);
    }

    public function suggestSeats(Request $request, Trip $trip)
    {
        $validated = $request->validate([
            'destination_station_id' => 'required|uuid|exists:stations,id',
            'boarding_station_id' => 'sometimes|uuid|exists:stations,id', // For semi-intelligent mode
            'quantity' => 'sometimes|integer|min:1',
        ]);

        $destinationStationId = $validated['destination_station_id'];
        $boardingStationId = $validated['boarding_station_id'] ?? null;
        $quantity = $validated['quantity'] ?? 1;

        // Utiliser le service d'optimisation
        $suggestions = $this->optimisationService->getSuggestedSeats(
            $trip->id,
            $destinationStationId,
            $quantity,
            $boardingStationId
        );
        $stats = $this->optimisationService->getTripOccupancyStats($trip->id);

        return response()->json([
            'suggested_seats' => $suggestions,
            'booking_type' => $stats['booking_type'],
            'occupancy' => [
                'total_seats' => $stats['total_seats'],
                'occupied_seats' => $stats['occupied_seats'],
                'occupied_seats_count' => $stats['occupied_seats_count'],
                'available_seats' => $stats['available_seats'],
                'occupancy_rate' => $stats['occupancy_rate'],
                'sold_tickets_count' => $stats['sold_tickets_count'],
            ],
        ]);
    }

    public function seatMap(Trip $trip, Request $request, TripSegmentService $segments)
    {
        $validated = $request->validate([
            'from_station_id' => 'nullable|uuid',
            'to_station_id' => 'nullable|uuid',
        ]);

        $reqFromId = $validated['from_station_id'] ?? null;
        $reqToId = $validated['to_station_id'] ?? null;

        $trip->load([
            'vehicle.vehicleType',
            'tripSeatOccupancies.toStation',
            'tripSeatOccupancies.fromStation',
            'tripSeatOccupancies.ticket.toStation',
            'tripSeatOccupancies.ticket.fromStation',
            'tripSeatOccupancies.ticket.seller:id,name',
            'route.routeStopOrders',
        ]);

        if (! $trip->vehicle?->vehicleType) {
            return response()->json([
                'message' => 'Aucun véhicule n’est assigné à ce voyage.',
                'vehicle_required' => true,
            ], 409);
        }

        $vehicleType = $trip->vehicle->vehicleType;
        $seatCount = $vehicleType->seat_count;
        $config = $vehicleType->seat_configuration ?? '2+2';
        $parts = array_map('intval', explode('+', $config));

        // Map station_id => stop_index using routeStopOrders
        $stationIndices = $segments->stationIndices($trip);
        $totalStops = count($stationIndices);

        // Determine requested segment indices (default to full route if not provided)
        $reqStartIndex = 0;
        $reqEndIndex = max(0, $totalStops - 1);

        if ($reqFromId && isset($stationIndices[$reqFromId])) {
            $reqStartIndex = $stationIndices[$reqFromId];
        }
        if ($reqToId && isset($stationIndices[$reqToId])) {
            $reqEndIndex = $stationIndices[$reqToId];
        }

        $canInspectTickets = in_array(auth()->user()?->role, [
            'admin',
            'supervisor',
            'superadmin',
            'super_admin',
            'executive',
        ], true);

        $occupiedSeatsLookup = $trip->tripSeatOccupancies->filter(function ($occupancy) use ($stationIndices, $reqStartIndex, $reqEndIndex) {
            if (! $occupancy->ticket) {
                $isOkohiHold = $occupancy->okohi_reward_request_id
                    && $occupancy->expires_at
                    && $occupancy->expires_at->isFuture();

                if (! $isOkohiHold) {
                    return false;
                }
            } elseif ($occupancy->ticket->status === 'cancelled') {
                return false;
            }

            // Get Ticket Segment Indices
            $ticketFromIdx = $stationIndices[$occupancy->from_station_id ?? $occupancy->ticket?->from_station_id] ?? null;
            $ticketToIdx = $stationIndices[$occupancy->to_station_id ?? $occupancy->ticket?->to_station_id] ?? null;

            // Safety fallback: if stations not found in current route, assume occupied to be safe
            if ($ticketFromIdx === null || $ticketToIdx === null) {
                return true;
            }

            // Check Overlap: [TicketStart, TicketEnd) vs [ReqStart, ReqEnd)
            // Overlap condition: Start1 < End2 && Start2 < End1
            return ($ticketFromIdx < $reqEndIndex) && ($reqStartIndex < $ticketToIdx);

        })->keyBy('seat_number')->map(function ($occupancy) use ($stationIndices, $totalStops, $canInspectTickets) {
            $isOkohiHold = ! $occupancy->ticket && $occupancy->okohi_reward_request_id;
            $ticketToStation = $occupancy->toStation ?? $occupancy->ticket?->toStation;
            $ticketFromIdx = $stationIndices[$occupancy->from_station_id ?? $occupancy->ticket?->from_station_id] ?? 0;
            $ticketToIdx = $stationIndices[$occupancy->to_station_id ?? $occupancy->ticket?->to_station_id] ?? null;

            $fromIdx = $ticketFromIdx;
            $toIdx = $ticketToIdx !== null ? $ticketToIdx : max(0, $totalStops - 1);

            return [
                'destination_name' => $isOkohiHold ? 'Attente Okohi 🎁' : ($ticketToStation->name ?? 'Inconnu'),
                'is_okohi_pending' => $isOkohiHold,
                'okohi_reward_request_id' => $occupancy->okohi_reward_request_id,
                'color' => $isOkohiHold ? '#F59E0B' : $this->getStopColor($fromIdx, $toIdx, $totalStops),
                'ticket_id' => $canInspectTickets ? $occupancy->ticket?->id : null,
                'ticket_number' => $canInspectTickets ? $occupancy->ticket?->ticket_number : null,
                'seller_name' => $canInspectTickets ? $occupancy->ticket?->seller?->name : null,
                'created_at' => $canInspectTickets ? $occupancy->ticket?->created_at?->toIso8601String() : null,
            ];
        });

        // Door positions from DB
        // '0' represents the front door aligned with driver (doesn't consume a seat)
        // Any other number represents a seat replaced by a door
        $dbDoorPositions = $vehicleType->door_positions ?? [];
        $doorPositions = array_filter($dbDoorPositions, function ($pos) {
            return $pos > 0;
        });

        // Use SeatMapService to ensure we have a valid 2D grid
        $seatMapService = app(SeatMapService::class);
        $storedSeatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
            'seat_count' => $seatCount,
            'seat_configuration' => $config,
            'door_positions' => $dbDoorPositions,
            'last_row_seats' => $vehicleType->last_row_seats ?? 5,
        ]);

        $seatMap = [];
        $processedSeatsCount = 0;

        foreach ($storedSeatMap as $row) {
            $processedRow = [];
            foreach ($row as $seat) {
                // If it's a seat, check occupancy
                if (isset($seat['type']) && $seat['type'] === 'seat') {
                    $seatNumber = (int) $seat['number'];
                    $isOccupied = $occupiedSeatsLookup->has($seatNumber);
                    $seatData = $occupiedSeatsLookup->get($seatNumber);

                    $processedRow[] = array_merge($seat, [
                        'isOccupied' => $isOccupied,
                        'isOkohiPending' => $isOccupied && ($seatData['is_okohi_pending'] ?? false),
                        'okohiRewardRequestId' => $isOccupied ? ($seatData['okohi_reward_request_id'] ?? null) : null,
                        'destination_name' => $isOccupied ? $seatData['destination_name'] : null,
                        'color' => $isOccupied ? $seatData['color'] : '#94A3B8',
                        'ticket_id' => $isOccupied ? ($seatData['ticket_id'] ?? null) : null,
                        'ticket_number' => $isOccupied ? ($seatData['ticket_number'] ?? null) : null,
                        'seller_name' => $isOccupied ? ($seatData['seller_name'] ?? null) : null,
                        'created_at' => $isOccupied ? ($seatData['created_at'] ?? null) : null,
                    ]);
                    $processedSeatsCount++;
                } else {
                    // Pass through other types (aisle, empty, driver, door)
                    $processedRow[] = $seat;
                }
            }
            $seatMap[] = $processedRow;
        }

        // Collect which seat numbers are truly available at each station:
        // freed there, minus seats already resold from that station.
        $freedSeatsByStation = [];
        $sellableSeatsByStation = [];
        foreach (array_keys($stationIndices) as $stationId) {
            $freedSeats = $segments->freedSeatsForStation($trip, $stationId);
            $sellableSeats = $segments->sellableSeatsForStation($trip, $stationId);

            if (! empty($freedSeats)) {
                $freedSeatsByStation[$stationId] = $freedSeats;
            }
            if (! empty($sellableSeats)) {
                $sellableSeatsByStation[$stationId] = $sellableSeats;
            }
        }

        return response()->json([
            'seat_map' => $seatMap,
            'total_seats' => $seatCount, // Total capacity
            'occupied_seats_count' => $occupiedSeatsLookup->count(),
            'available_seats_count' => $seatCount - $occupiedSeatsLookup->count(),
            'sold_tickets_count' => Ticket::where('trip_id', $trip->id)
                ->where('status', 'issued')
                ->count(),
            'vehicle_type' => $vehicleType,
            'freed_seats_by_station' => $freedSeatsByStation,
            'sellable_seats_by_station' => $sellableSeatsByStation,
        ]);
    }

    /**
     * Determines the color for a stop based on the departure and destination station indices.
     * Hue represents the departure station, lightness represents the distance/progress to the destination.
     */
    private function getStopColor(int $fromIdx, int $toIdx, int $totalStops): string
    {
        $hues = [
            220, // 0: Blue (Origin)
            270, // 1: Purple
            25,  // 2: Orange
            165, // 3: Teal
            330, // 4: Rose
            195, // 5: Cyan
            140, // 6: Green
            350, // 7: Red
        ];

        $hue = $hues[$fromIdx % count($hues)];

        $remainingStops = $totalStops - 1 - $fromIdx;
        $ratio = 0.0;
        if ($remainingStops > 1) {
            // Keep this calculation aligned with getStationColor() in the
            // ticketing frontend: the nearest destination starts at 0 and
            // the furthest destination ends at 1.
            $ratio = ($toIdx - $fromIdx - 1) / ($remainingStops - 1);
            $ratio = max(0.0, min(1.0, $ratio));
        }

        // Same saturation and lightness range as the destination cards.
        $lightness = 75 - ($ratio * 40);

        return "hsl({$hue}, 80%, ".round($lightness, 1).'%)';
    }

    public function details(Trip $trip)
    {
        $trip->load([
            'route.originStation.destination',
            'route.destinationStation.destination',
            'route.routeStopOrders.station.destination',
            'vehicle.vehicleType',
            'originStation.destination',
            'destinationStation.destination',
            'tripSeatOccupancies.fromStation',
            'tripSeatOccupancies.toStation',
            'tripSeatOccupancies.ticket.seller',
            'tripSeatOccupancies.ticket.finalDestinationStation',
            'tripSeatOccupancies.ticket.transferStation',
            'tripSeatOccupancies.ticket.connection.destinationStation',
            'tripSeatOccupancies.ticket.connection.trip',
        ]);

        // A connection ticket keeps the id of its inbound trip. The occupancy,
        // rather than ticket.trip_id, tells us on which trip it owns a seat.
        $occupancies = $trip->tripSeatOccupancies
            ->filter(fn ($occupancy) => $occupancy->ticket?->status === 'issued')
            ->map(function ($occupancy) use ($trip) {
                $ticket = $occupancy->ticket;
                $ticketConnection = $ticket->connection;
                $isConnectionLeg = $ticketConnection?->trip_id === $trip->id;

                return [
                    'id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'seat_number' => $occupancy->seat_number,
                    'passenger_name' => $ticket->passenger_name,
                    'passenger_phone' => $ticket->passenger_phone,
                    'price' => $ticket->price,
                    'seller_id' => $ticket->seller_id,
                    'seller' => $ticket->seller,
                    'status' => $ticket->status,
                    'created_at' => $ticket->created_at,
                    'from_station_id' => $occupancy->from_station_id,
                    'to_station_id' => $occupancy->to_station_id,
                    'from_station' => $occupancy->fromStation,
                    'to_station' => $occupancy->toStation,
                    'journey_type' => $isConnectionLeg
                        ? 'connection'
                        : ($ticketConnection ? 'connection_origin' : 'direct'),
                    'final_destination' => $ticket->finalDestinationStation,
                    'transfer_station' => $ticket->transferStation,
                    'connection_destination' => $ticketConnection?->destinationStation,
                    'connection_trip' => $ticketConnection?->trip ? [
                        'id' => $ticketConnection->trip->id,
                        'code' => $ticketConnection->trip->code,
                        'display_name' => $ticketConnection->trip->display_name,
                        'departure_at' => $ticketConnection->trip->departure_at,
                    ] : null,
                    'connection_status' => $ticketConnection?->status,
                    'connection_has_conflict' => $ticketConnection?->hasConflict() ?? false,
                    'connection_conflict_reason' => data_get($ticketConnection?->settings, 'conflict_reason'),
                ];
            })
            ->sortBy(fn ($occupancy) => (int) $occupancy['seat_number'])
            ->values();

        // Keep the trip payload compact; the normalized list above is the only
        // occupancy representation consumed by the details modal.
        $trip->unsetRelation('tripSeatOccupancies');

        // Fetch compatible pending/ready connections waiting at the trip's origin station
        $transitPool = TicketConnection::with(['ticket.fromStation', 'destinationStation'])
            ->where('transfer_station_id', $trip->origin_station_id)
            ->where('route_id', $trip->route_id)
            ->whereIn('status', ['pending', 'ready'])
            ->get()
            ->map(function ($connection) {
                return [
                    'id' => $connection->id,
                    'ticket_id' => $connection->ticket_id,
                    'ticket_number' => $connection->ticket->ticket_number,
                    'passenger_name' => $connection->ticket->passenger_name,
                    'passenger_phone' => $connection->ticket->passenger_phone,
                    'from_station_name' => $connection->ticket->fromStation->name ?? 'Inconnue',
                    'destination_station_name' => $connection->destinationStation->name ?? 'Inconnue',
                    'status' => $connection->status,
                    'planned_ready_at' => $connection->planned_ready_at,
                    'estimated_ready_at' => $connection->estimated_ready_at,
                ];
            });

        return response()->json([
            'trip' => $trip,
            'driver' => $trip->driver,
            'assistant' => $trip->assistant,
            'occupancies' => $occupancies,
            'transit_pool' => $transitPool,
        ]);
    }

    public function latestPosition(Trip $trip)
    {
        $report = CrewStatusReport::with('crewMember')
            ->where('trip_id', $trip->id)
            ->latest('reported_at')
            ->first();

        return response()->json([
            'report' => $report ? [
                'id' => $report->id,
                'trip_id' => $report->trip_id,
                'crew_member' => $report->crewMember ? [
                    'id' => $report->crewMember->id,
                    'name' => $report->crewMember->name,
                ] : null,
                'status' => $report->status,
                'latitude' => $report->latitude,
                'longitude' => $report->longitude,
                'note' => $report->note,
                'metadata' => $report->metadata,
                'reported_at' => $report->reported_at?->toIso8601String(),
            ] : null,
        ]);
    }

    public function statusReports(Trip $trip)
    {
        $reports = CrewStatusReport::with('crewMember')
            ->where('trip_id', $trip->id)
            ->orderByDesc('reported_at')
            ->get()
            ->map(function ($report) {
                return [
                    'id' => $report->id,
                    'trip_id' => $report->trip_id,
                    'crew_member' => $report->crewMember ? [
                        'id' => $report->crewMember->id,
                        'name' => $report->crewMember->name,
                    ] : null,
                    'status' => $report->status,
                    'latitude' => $report->latitude,
                    'longitude' => $report->longitude,
                    'note' => $report->note,
                    'metadata' => $report->metadata,
                    'reported_at' => $report->reported_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'reports' => $reports,
        ]);
    }
}
