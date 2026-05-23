<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Trip;
use App\Services\OptimisationService;
use App\Services\TripSegmentService;
use Illuminate\Http\Request;

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
        $query = Trip::withCount('tripSeatOccupancies as occupied_seats')
            ->with([
                'route.originStation',
                'route.destinationStation',
                'route.routeStopOrders.station',
                'vehicle.vehicleType',
            ])
            ->where('departure_at', '>=', now())
            ->orderBy('departure_at');

        if ($user && $user->role === 'seller') {
            $assignedStationIds = \App\Models\UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->pluck('station_id')
                ->toArray();

            // Filtrer par route - tous les voyages passant par les stations assignées sont visibles
            // Le contrôle sales_control s'applique uniquement au moment de la vente
            $query->whereHas('route', function ($q) use ($assignedStationIds) {
                $q->whereIn('origin_station_id', $assignedStationIds)
                    ->orWhereHas('routeStopOrders', function ($sq) use ($assignedStationIds) {
                        $sq->whereIn('station_id', $assignedStationIds);
                    });
            });
        }

        return $query->get();
    }

    public function byRouteAndDate(string $routeId, string $date)
    {
        Route::whereKey($routeId)->firstOrFail();

        return Trip::with([
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
        ])
            ->withCount('tripSeatOccupancies as occupied_seats')
            ->where('route_id', $routeId)
            ->whereDate('departure_at', $date)
            ->orderBy('departure_at')
            ->get();
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
            ->withCount('tripSeatOccupancies as occupied_seats')
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
                'available_seats' => $stats['available_seats'],
                'occupancy_rate' => $stats['occupancy_rate'],
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

        $trip->load(['vehicle.vehicleType', 'tripSeatOccupancies.ticket.toStation', 'tripSeatOccupancies.ticket.fromStation', 'route.routeStopOrders']);

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

        $occupiedSeatsLookup = $trip->tripSeatOccupancies->filter(function ($occupancy) use ($stationIndices, $reqStartIndex, $reqEndIndex) {
            if (! $occupancy->ticket) {
                return false;
            }

            if ($occupancy->ticket->status === 'cancelled') {
                return false;
            }

            // Get Ticket Segment Indices
            $ticketFromIdx = $stationIndices[$occupancy->ticket->from_station_id] ?? null;
            $ticketToIdx = $stationIndices[$occupancy->ticket->to_station_id] ?? null;

            // Safety fallback: if stations not found in current route, assume occupied to be safe
            if ($ticketFromIdx === null || $ticketToIdx === null) {
                return true;
            }

            // Check Overlap: [TicketStart, TicketEnd) vs [ReqStart, ReqEnd)
            // Overlap condition: Start1 < End2 && Start2 < End1
            return ($ticketFromIdx < $reqEndIndex) && ($reqStartIndex < $ticketToIdx);

        })->keyBy('seat_number')->map(function ($occupancy) use ($stationIndices, $totalStops) {
            $ticketToStation = $occupancy->ticket->toStation;
            $ticketToIdx = $stationIndices[$occupancy->ticket->to_station_id] ?? null;
            $stopIndex = $ticketToIdx !== null ? $ticketToIdx : max(0, $totalStops - 1);

            return [
                'destination_name' => $ticketToStation->name ?? 'Inconnu',
                'color' => $this->getStopColor($stopIndex, $totalStops),
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
        $seatMapService = app(\App\Services\SeatMapService::class);
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
                        'destination_name' => $isOccupied ? $seatData['destination_name'] : null,
                        'color' => $isOccupied ? $seatData['color'] : '#94A3B8',
                    ]);
                    $processedSeatsCount++;
                } else {
                    // Pass through other types (aisle, empty, driver, door)
                    $processedRow[] = $seat;
                }
            }
            $seatMap[] = $processedRow;
        }

        return response()->json([
            'seat_map' => $seatMap,
            'total_seats' => $seatCount, // Total capacity
            'occupied_seats_count' => $occupiedSeatsLookup->count(),
            'available_seats_count' => $seatCount - $occupiedSeatsLookup->count(),
            'vehicle_type' => $vehicleType,
        ]);
    }

    /**
     * Determines the color for a stop based on its index in the route.
     * Uses blue gradient: light blue for close destinations, dark blue for far destinations
     */
    private function getStopColor(int $stopIndex, int $totalStops): string
    {
        if ($totalStops <= 1) {
            return '#3B82F6';
        } // Blue-500 default

        // Normalize the order between 0 and 1
        $ratio = $stopIndex / ($totalStops - 1); // 0 = origin, 1 = last stop

        // Blue gradient
        // HSL: 220 (Blue)
        // Saturation: 100%
        // Lightness: From 85% (closest) to 30% (furthest)

        $lightness = 85 - ($ratio * 55); // 85 -> 30

        return 'hsl(220, 100%, '.round($lightness, 2).'%)';
    }
}
