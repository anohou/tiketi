<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Trip;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Services\SeatMapService;
use Inertia\Inertia;

class TicketingController extends Controller
{
    public function index()
    {
        return Inertia::render('Seller/Ticketing', $this->getTicketingData());
    }

    public function horizontal()
    {
        return Inertia::render('Seller/TicketingHorizontal', $this->getTicketingData());
    }

    private function getTicketingData(): array
    {
        $user = auth()->user();
        $isAdmin = $user->role === 'admin';

        $assignedStationIds = $isAdmin ? [] : UserStationAssignment::where('user_id', $user->id)
            ->where('active', true)
            ->pluck('station_id')
            ->toArray();

        $hasActiveAssignment = $isAdmin || count($assignedStationIds) > 0;
        $assignedStation = (!$isAdmin && $hasActiveAssignment)
            ? Station::find($assignedStationIds[0])?->name
            : null;

        $trips = $this->loadTrips($isAdmin, $assignedStationIds);
        $routeFares = $this->loadFares($isAdmin, $assignedStationIds);
        $routes = $this->loadRoutes($isAdmin, $assignedStationIds);

        $this->enrichTripsWithSeatCounts($trips);

        $destinations = $this->collectDestinations($trips);

        return [
            'trips' => $trips,
            'routeFares' => $routeFares,
            'routes' => $routes,
            'vehicles' => Vehicle::with('vehicleType')->orderBy('identifier')->get(['id', 'identifier', 'seat_count', 'vehicle_type_id']),
            'destinations' => $destinations,
            'hasActiveAssignment' => $hasActiveAssignment,
            'assignedStation' => $assignedStation,
        ];
    }

    private function loadTrips(bool $isAdmin, array $assignedStationIds)
    {
        $baseQuery = Trip::withCount('tripSeatOccupancies as occupied_seats')
            ->where('departure_at', '>=', now())
            ->orderBy('departure_at');

        if ($isAdmin) {
            return $baseQuery
                ->with(['route.originStation', 'route.routeStopOrders', 'vehicle.vehicleType'])
                ->get();
        }

        $assignedRouteIds = Route::where(function ($query) use ($assignedStationIds) {
            $query->whereIn('origin_station_id', $assignedStationIds)
                  ->orWhereHas('routeStopOrders', function ($q) use ($assignedStationIds) {
                      $q->whereIn('station_id', $assignedStationIds);
                  });
        })
        ->where('active', true)
        ->pluck('id')
        ->toArray();

        return $baseQuery
            ->with([
                'route.originDestination',
                'route.targetDestination',
                'route.originStation.destination',
                'route.destinationStation.destination',
                'route.routeStopOrders.station.destination',
                'vehicle.vehicleType',
                'originStation.destination',
                'destinationStation.destination',
            ])
            ->whereIn('route_id', $assignedRouteIds)
            ->get();
    }

    private function loadFares(bool $isAdmin, array $assignedStationIds)
    {
        if ($isAdmin) {
            return RouteFare::with(['fromStation.destination', 'toStation.destination'])->get();
        }

        $fares = RouteFare::with(['fromStation.destination', 'toStation.destination'])
            ->where(function ($query) use ($assignedStationIds) {
                $query->whereIn('from_station_id', $assignedStationIds)
                    ->orWhere(function ($q) use ($assignedStationIds) {
                        $q->where('is_bidirectional', true)
                          ->whereIn('to_station_id', $assignedStationIds);
                    });
            })
            ->get();

        return $fares->map(function ($fare) use ($assignedStationIds) {
            $isReversed = $fare->is_bidirectional
                && in_array($fare->to_station_id, $assignedStationIds)
                && !in_array($fare->from_station_id, $assignedStationIds);

            $fareArray = $fare->toArray();
            $fareArray['is_reversed'] = $isReversed;

            if ($isReversed) {
                [$fareArray['from_station'], $fareArray['to_station']] = [$fareArray['to_station'], $fareArray['from_station']];
                [$fareArray['from_station_id'], $fareArray['to_station_id']] = [$fareArray['to_station_id'], $fareArray['from_station_id']];
            }

            return $fareArray;
        })->values();
    }

    private function loadRoutes(bool $isAdmin, array $assignedStationIds)
    {
        if ($isAdmin) {
            return Route::orderBy('name')->get(['id', 'name']);
        }

        return Route::with(['originDestination', 'targetDestination'])
            ->where(function ($query) use ($assignedStationIds) {
                $query->whereIn('origin_station_id', $assignedStationIds)
                      ->orWhereIn('destination_station_id', $assignedStationIds);
            })
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    private function enrichTripsWithSeatCounts($trips): void
    {
        $seatMapService = app(SeatMapService::class);

        foreach ($trips as $trip) {
            $vehicleType = $trip->vehicle?->vehicleType;
            if (!$vehicleType) {
                $trip->total_seats = 0;
                $trip->available_seats = 0;
                continue;
            }

            $seatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
                'seat_count' => $vehicleType->seat_count,
                'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
                'door_positions' => $vehicleType->door_positions ?? [],
                'last_row_seats' => $vehicleType->last_row_seats ?? 5,
            ]);

            $totalSeats = $this->countSeatsInMap($seatMap);
            $trip->total_seats = $totalSeats;
            $trip->available_seats = $totalSeats - ($trip->occupied_seats ?? 0);
        }
    }

    private function countSeatsInMap(array $seatMap): int
    {
        $count = 0;
        foreach ($seatMap as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $cell) {
                if (($cell['type'] ?? null) === 'seat') {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function collectDestinations($trips)
    {
        $cities = collect();

        foreach ($trips as $trip) {
            $route = $trip->route;
            if (!$route) continue;

            if ($route->originStation?->city) {
                $cities->push($route->originStation->city);
            }
            if ($route->destinationStation?->city) {
                $cities->push($route->destinationStation->city);
            }

            foreach ($route->routeStopOrders ?? [] as $stopOrder) {
                if ($stopOrder->station?->city) {
                    $cities->push($stopOrder->station->city);
                }
            }
        }

        return $cities->unique()->filter()->sort()->values()->map(function ($city) {
            return [
                'id' => $city,
                'name' => $city
            ];
        });
    }

    public function getSeatMap($tripId)
    {
        $trip = Trip::with(['vehicle.vehicleType', 'tripSeatOccupancies.ticket.toStation', 'route.routeStopOrders'])->findOrFail($tripId);

        $vehicleType = $trip->vehicle->vehicleType;
        $seatCount = $trip->vehicle->seat_count;
        $occupiedSeats = $trip->tripSeatOccupancies->pluck('seat_number')->toArray();

        $stopOrders = $trip->route->routeStopOrders->pluck('stop_index', 'station_id');
        $totalStops = $stopOrders->count();

        $seatMapService = app(SeatMapService::class);
        $seatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
            'seat_count' => $seatCount,
            'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
            'door_positions' => $vehicleType->door_positions ?? [],
            'last_row_seats' => $vehicleType->last_row_seats ?? 5,
        ]);

        foreach ($seatMap as &$row) {
            foreach ($row as &$seat) {
                if (($seat['type'] ?? null) !== 'seat' || !isset($seat['number'])) {
                    continue;
                }

                $seatNumber = $seat['number'];
                $occupancy = $trip->tripSeatOccupancies->firstWhere('seat_number', $seatNumber);
                $seat['isOccupied'] = in_array($seatNumber, $occupiedSeats);

                if ($seat['isOccupied'] && $occupancy?->ticket) {
                    $stopIndex = $stopOrders[$occupancy->ticket->to_station_id] ?? 0;
                    $seat['color'] = $this->getStopColor($stopIndex, $totalStops);
                    $seat['destination_name'] = $occupancy->ticket->toStation->name ?? 'Inconnu';
                    $seat['ticket_id'] = $occupancy->ticket->id;
                    $seat['ticket_uuid'] = $occupancy->ticket->uuid ?? null;
                    $seat['ticket_number'] = $occupancy->ticket->ticket_number;
                } else {
                    $seat['color'] = '#94A3B8';
                    $seat['destination_name'] = null;
                }
            }
        }

        return response()->json([
            'seat_map' => $seatMap,
            'vehicle_type' => $vehicleType,
            'total_seats' => $seatCount,
            'occupied_seats' => count($occupiedSeats),
            'available_seats' => $seatCount - count($occupiedSeats),
        ]);
    }

    private function getStopColor(int $stopIndex, int $totalStops): string
    {
        if ($totalStops <= 1) return '#3B82F6';

        $ratio = $stopIndex / ($totalStops - 1);
        $lightness = 85 - ($ratio * 55);

        return "hsl(220, 100%, {$lightness}%)";
    }
}
