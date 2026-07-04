<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\SeatMapService;
use App\Services\TripSegmentService;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

class TicketingController extends Controller
{
    public function index()
    {
        return Inertia::render('Seller/Ticketing', $this->getTicketingData(request('trip_id')));
    }

    public function horizontal()
    {
        return Inertia::render('Seller/TicketingHorizontal', $this->getTicketingData(request('trip_id')));
    }

    private function getTicketingData(?string $selectedTripId = null): array
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        $assignedStationIds = $user->getActiveStationIds();
        $hasActiveAssignment = $isAdmin || count($assignedStationIds) > 0;
        $assignedStationModel = (! $isAdmin && $hasActiveAssignment)
            ? Station::find($assignedStationIds[0])
            : null;
        $assignedStation = $assignedStationModel?->name;

        $trips = $this->loadTrips($isAdmin, $assignedStationIds, $selectedTripId);
        $routeFares = $this->loadFares($isAdmin, $assignedStationIds);
        $routes = $this->loadRoutes($isAdmin);

        $this->enrichTripsWithSeatCounts($trips);

        $destinations = $this->collectDestinations($trips);

        return [
            'trips' => $trips,
            'routeFares' => $routeFares,
            'routes' => $routes,
            'vehicles' => Vehicle::with('vehicleType')->where('active', true)->orderBy('identifier')->get(['id', 'identifier', 'seat_count', 'vehicle_type_id']),
            'destinations' => $destinations,
            'hasActiveAssignment' => $hasActiveAssignment,
            'assignedStationId' => $assignedStationModel?->id,
            'assignedStation' => $assignedStation,
        ];
    }

    private function loadTrips(bool $isAdmin, array $assignedStationIds, ?string $selectedTripId = null)
    {
        $user = auth()->user();
        $showHistory = request()->boolean('show_history');

        $baseQuery = Trip::withCount('tripSeatOccupancies as occupied_seats');

        if ($showHistory) {
            // Unlimited past trips for history
            $tripsQuery = (clone $baseQuery)
                ->where('departure_at', '<', now()->subHours(1))
                ->orderBy('departure_at', 'desc');
        } else {
            // Standard active trips
            $hours = (in_array($user->role, ['admin', 'supervisor', 'superadmin'])) ? 48 : 1;
            $tripsQuery = (clone $baseQuery)
                ->where('departure_at', '>=', now()->subHours($hours))
                ->upcomingFirst();
        }

        $withRelations = [
            'route.originStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
        ];

        if ($isAdmin) {
            $finalQuery = $showHistory
                ? $tripsQuery->with($withRelations)
                : (clone $baseQuery)->with($withRelations)->upcomingFirst();
        } else {
            $assignedRouteIds = $user->accessibleRoutesQuery()
                ->pluck('id')
                ->toArray();

            $finalQuery = $tripsQuery->whereIn('route_id', $assignedRouteIds)->with($withRelations);
        }

        // Apply pagination if history is requested
        if ($showHistory) {
            return $finalQuery->paginate(15)->withQueryString();
        }

        $trips = $finalQuery->get();

        // If a specific trip was requested and it's not in the list, fetch and add it
        if ($selectedTripId && ! $trips->contains('id', $selectedTripId)) {
            $requestedTrip = Trip::withCount('tripSeatOccupancies as occupied_seats')
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
                ->find($selectedTripId);

            if ($requestedTrip) {
                $trips->push($requestedTrip);
            }
        }

        return $trips;
    }

    private function loadFares(bool $isAdmin, array $assignedStationIds)
    {
        if ($isAdmin) {
            return RouteFare::with(['fromStation.destination', 'toStation.destination'])->where('active', true)->get();
        }

        $fares = RouteFare::with(['fromStation.destination', 'toStation.destination'])
            ->where('active', true)
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
                && ! in_array($fare->from_station_id, $assignedStationIds);

            $fareArray = $fare->toArray();
            $fareArray['is_reversed'] = $isReversed;

            if ($isReversed) {
                [$fareArray['from_station'], $fareArray['to_station']] = [$fareArray['to_station'], $fareArray['from_station']];
                [$fareArray['from_station_id'], $fareArray['to_station_id']] = [$fareArray['to_station_id'], $fareArray['from_station_id']];
            }

            return $fareArray;
        })->values();
    }

    private function loadRoutes(bool $isAdmin)
    {
        if ($isAdmin) {
            return Route::orderBy('name')->get(['id', 'name']);
        }

        return auth()->user()->accessibleRoutesQuery()
            ->with(['originDestination', 'targetDestination'])
            ->orderBy('name')
            ->get();
    }

    private function enrichTripsWithSeatCounts($trips): void
    {
        $items = ($trips instanceof LengthAwarePaginator) ? $trips->items() : $trips;

        $seatMapService = app(SeatMapService::class);
        $segments = app(TripSegmentService::class);

        foreach ($items as $trip) {
            $vehicleType = $trip->vehicle?->vehicleType;
            if (! $vehicleType) {
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
            $trip->available_seats = $segments->availableSeatCount($trip);
        }
    }

    private function countSeatsInMap(array $seatMap): int
    {
        $count = 0;
        foreach ($seatMap as $row) {
            if (! is_array($row)) {
                continue;
            }
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
            if (! $route) {
                continue;
            }

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
                'name' => $city,
            ];
        });
    }
}
