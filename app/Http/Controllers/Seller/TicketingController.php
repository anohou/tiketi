<?php

namespace App\Http\Controllers\Seller;

use App\Domain\Trips\CrewTripVisibility;
use App\Domain\Trips\InvalidTripTransition;
use App\Domain\Trips\TripStateMachine;
use App\Domain\Trips\TripStatus;
use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\TicketConnection;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\SeatMapService;
use App\Services\TripSegmentService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
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
            'connectionFares' => RouteFare::with(['fromStation.destination', 'toStation.destination'])
                ->where('active', true)->get(),
            'connectionRoutes' => Route::with(['originStation', 'destinationStation', 'routeStopOrders.station'])
                ->where('active', true)->orderBy('name')->get(),
            'routes' => $routes,
            'vehicles' => Vehicle::with('vehicleType')->where('active', true)->orderBy('identifier')->get(['id', 'identifier', 'seat_count', 'vehicle_type_id']),
            'destinations' => $destinations,
            'hasActiveAssignment' => $hasActiveAssignment,
            'assignedStationId' => $assignedStationModel?->id,
            'assignedStation' => $assignedStation,
            'okohiIntegrationActive' => TicketSetting::getSettings()->hasOkohiIntegration(),
            'replicableTrips' => Trip::where('is_replicable', true)
                ->get(['id', 'route_id', 'departure_at', 'allows_open_connections', 'automatic_connection_allocation', 'code'])
                ->map(function ($trip) {
                    return [
                        'id' => $trip->id,
                        'route_id' => $trip->route_id,
                        'time' => $trip->departure_at ? $trip->departure_at->format('H:i') : '00:00',
                        'allows_open_connections' => (bool) $trip->allows_open_connections,
                        'automatic_connection_allocation' => $trip->automatic_connection_allocation,
                        'code' => $trip->code,
                    ];
                })
                ->unique(fn ($item) => $item['route_id'].'-'.$item['time'])
                ->values(),
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
            [$windowStart, $windowEnd] = app(CrewTripVisibility::class)->operationalWindow();
            $activeStart = now()->subHours((int) config('transport.operations.active_trip_lookback_hours', 48));
            $tripsQuery = (clone $baseQuery)
                ->where(function ($trips) use ($windowStart, $windowEnd, $activeStart) {
                    $trips->where(function ($scheduled) use ($windowStart, $windowEnd) {
                        $scheduled->where('status', 'scheduled')
                            ->where('departure_at', '>=', $windowStart)
                            ->where('departure_at', '<', $windowEnd);
                    })->orWhere(function ($active) use ($activeStart, $windowEnd) {
                        $active->whereIn('status', ['boarding', 'delayed', 'departed'])
                            ->where('departure_at', '>=', $activeStart)
                            ->where('departure_at', '<', $windowEnd);
                    });
                })
                ->upcomingFirst();
        }

        $withRelations = [
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
            'originStation',
            'destinationStation',
            'tickets.connection.destinationStation',
            'tickets.connection.transferStation',
            'tickets.connection.trip',
            'assignedConnections.destinationStation',
            'assignedConnections.transferStation',
            'assignedConnections.trip',
        ];

        if ($isAdmin) {
            $finalQuery = $tripsQuery->with($withRelations);
        } else {
            $assignedRouteIds = $user->accessibleRoutesQuery()
                ->pluck('id')
                ->toArray();

            $finalQuery = $tripsQuery->whereIn('route_id', $assignedRouteIds)->with($withRelations);
        }

        // Apply pagination if history is requested
        if ($showHistory) {
            $paginated = $finalQuery->paginate(15)->withQueryString();
            $paginated->getCollection()->each(function ($trip) {
                $hasAssigned = $trip->assignedConnections->isNotEmpty();
                $hasTransit = TicketConnection::where('transfer_station_id', $trip->origin_station_id)
                    ->where('route_id', $trip->route_id)
                    ->whereIn('status', ['pending', 'ready'])
                    ->exists();
                $trip->has_connections = $hasAssigned || $hasTransit;
            });

            return $paginated;
        }

        $trips = $finalQuery->get();

        // If a specific trip was requested and it's not in the list, fetch and add it
        if ($selectedTripId && ! $trips->contains('id', $selectedTripId)) {
            $requestedTrip = Trip::withCount('tripSeatOccupancies as occupied_seats')
                ->with(array_merge($withRelations, [
                    'route.originDestination',
                    'route.targetDestination',
                    'route.originStation.destination',
                    'route.destinationStation.destination',
                    'route.routeStopOrders.station.destination',
                    'originStation.destination',
                    'destinationStation.destination',
                ]))
                ->find($selectedTripId);

            if ($requestedTrip && ($isAdmin || in_array($requestedTrip->route_id, $assignedRouteIds ?? [], true))) {
                $trips->push($requestedTrip);
            }
        }

        $trips->each(function ($trip) {
            $hasAssigned = $trip->assignedConnections->isNotEmpty();
            $hasTransit = TicketConnection::where('transfer_station_id', $trip->origin_station_id)
                ->where('route_id', $trip->route_id)
                ->whereIn('status', ['pending', 'ready'])
                ->exists();
            $trip->has_connections = $hasAssigned || $hasTransit;
        });

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
            // Fetch compatible pending/ready connections waiting at the trip's origin station
            $compatiblePool = TicketConnection::with(['ticket.fromStation', 'destinationStation'])
                ->where('transfer_station_id', $trip->origin_station_id)
                ->where('route_id', $trip->route_id)
                ->whereIn('status', ['pending', 'ready'])
                ->get();

            $poolSummary = [
                'total' => $compatiblePool->count(),
                'statuses' => $compatiblePool->countBy('status')->all(),
                'destinations' => $compatiblePool
                    ->groupBy('destination_station_id')
                    ->map(function ($items) {
                        $destination = $items->first()?->destinationStation;
                        $total = $items->count();

                        return [
                            'id' => $destination?->id,
                            'name' => $destination?->name ?? 'Destination inconnue',
                            'count' => $total,
                            'unassigned_count' => $total,
                        ];
                    })
                    ->values()
                    ->all(),
            ];

            $trip->connection_summary = [
                'outgoing' => $this->summarizeConnections(
                    $trip->tickets
                        ->where('status', '!=', 'cancelled')
                        ->pluck('connection')
                        ->filter()
                ),
                'incoming' => $this->summarizeConnections($trip->assignedConnections),
                'pool' => $poolSummary,
            ];
        }
    }

    private function summarizeConnections($connections): array
    {
        $connections = collect($connections)->values();

        return [
            'total' => $connections->count(),
            'conflict_count' => $connections->filter->hasConflict()->count(),
            'statuses' => $connections->countBy('status')->all(),
            'transfer_stations' => $connections
                ->pluck('transferStation.name')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'destinations' => $connections
                ->groupBy('destination_station_id')
                ->map(function ($items) {
                    $destination = $items->first()?->destinationStation;
                    $total = $items->count();

                    // Group connection seats by connection trip code
                    $assignedTrips = $items->whereNotNull('trip_id')
                        ->groupBy('trip_id')
                        ->map(function ($tripItems) {
                            $trip = $tripItems->first()?->trip;

                            return [
                                'trip_id' => $trip?->id,
                                'trip_code' => $trip?->code ?? 'N/A',
                                'seats' => $tripItems->pluck('seat_number')->filter()->unique()->values()->all(),
                            ];
                        })
                        ->values()
                        ->all();

                    $assignedCount = $items->whereNotNull('seat_number')->count();

                    return [
                        'id' => $destination?->id,
                        'name' => $destination?->name ?? 'Destination inconnue',
                        'count' => $total,
                        'assigned_trips' => $assignedTrips,
                        'unassigned_count' => max(0, $total - $assignedCount),
                        'conflict_count' => $items->filter->hasConflict()->count(),
                        'statuses' => $items->countBy('status')->all(),
                    ];
                })
                ->values()
                ->all(),
        ];
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

    public function updateStatus(Request $request, Trip $trip)
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:scheduled,boarding,departed,arrived,cancelled,delayed,embarquement,parti,en_route,arrive,arrivé,retardé,retarde'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        if ($user?->role === 'seller') {
            $target = TripStatus::normalize($validated['status']);
            $routeStationIds = array_keys(app(TripSegmentService::class)->stationIndices($trip));
            $hasLocalAccess = array_intersect($user->getActiveStationIds(), $routeStationIds) !== [];
            abort_unless($hasLocalAccess, 403, 'Ce voyage ne dessert aucune de vos gares affectées.');
            abort_unless(
                in_array($target, ['scheduled', 'boarding', 'delayed'], true),
                403,
                'Un vendeur ne peut pas déclarer seul le départ, l’arrivée ou l’annulation globale.',
            );
        }

        try {
            app(TripStateMachine::class)->transition(
                $trip,
                $validated['status'],
                $request->user(),
                'seller_web',
                $validated['reason'] ?? null,
            );
        } catch (InvalidTripTransition $exception) {
            return redirect()->back()->withErrors(['status' => $exception->getMessage()]);
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

        return redirect()->back()->with('status', 'Statut du voyage mis à jour.');
    }

    public function tids(Request $request)
    {
        $stationId = $request->input('station_id') ?: $request->input('station');

        // Auto-detect the current station of the user/seller if no station param is passed
        if (! $stationId && auth()->check()) {
            $user = auth()->user();
            $assignedStationIds = $user->getActiveStationIds();
            if (count($assignedStationIds) > 0) {
                $stationId = $assignedStationIds[0];
            }
        }

        $baseQuery = Trip::withCount('tripSeatOccupancies as occupied_seats');

        // We load trips from 12 hours ago to 36 hours in the future
        $windowStart = now()->subHours(12);
        $windowEnd = now()->addHours(36);

        $tripsQuery = $baseQuery
            ->whereBetween('departure_at', [$windowStart, $windowEnd])
            ->whereIn('status', ['scheduled', 'boarding', 'delayed', 'departed', 'cancelled'])
            ->upcomingFirst();

        $withRelations = [
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
            'originStation',
            'destinationStation',
        ];

        $tripsQuery->with($withRelations);

        if ($stationId) {
            $tripsQuery->where(function ($query) use ($stationId) {
                $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $stationId);
                if ($isUuid) {
                    $query->where('origin_station_id', $stationId);
                } else {
                    $query->whereHas('originStation', function ($q) use ($stationId) {
                        $q->where('name', 'like', "%{$stationId}%")
                            ->orWhere('city', 'like', "%{$stationId}%");
                    });
                }
            });
        }

        $trips = $tripsQuery->get();
        $this->enrichTripsWithSeatCounts($trips);

        // Load all active stations for the filter/selector
        $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'city']);

        $selectedStation = null;
        if ($stationId) {
            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $stationId);
            if ($isUuid) {
                $selectedStation = Station::find($stationId);
            } else {
                $selectedStation = Station::where('name', 'like', "%{$stationId}%")->first();
            }
        }

        return Inertia::render('Seller/TidsBoard', [
            'trips' => $trips,
            'stations' => $stations,
            'selectedStationId' => $selectedStation?->id,
            'selectedStationName' => $selectedStation?->name,
        ]);
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
}
