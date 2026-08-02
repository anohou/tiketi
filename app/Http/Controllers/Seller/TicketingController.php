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
use App\Models\StationVehicleAssignment;
use App\Models\TicketConnection;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\SeatMapService;
use App\Services\TripSegmentService;
use App\Services\TripStationProgression;
use App\Services\TripTimingService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TicketingController extends Controller
{
    public function index()
    {
        return Inertia::render('Seller/Ticketing', $this->getTicketingData(request('trip_id')));
    }

    public function focus()
    {
        return Inertia::render('Seller/Ticketing', [
            ...$this->getTicketingData(request('trip_id')),
            'focusMode' => true,
        ]);
    }

    private function getTicketingData(?string $selectedTripId = null): array
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();
        $canSelectTripOrigin = $isAdmin || $user->role === 'supervisor';

        $assignedStationIds = $user->getActiveStationIds();
        $hasActiveAssignment = $isAdmin || count($assignedStationIds) > 0;
        $assignedStationModel = (! $isAdmin && $hasActiveAssignment)
            ? Station::find($assignedStationIds[0])
            : null;
        $assignedStation = $assignedStationModel?->name;

        $trips = $this->loadTrips($isAdmin, $assignedStationIds, $selectedTripId);
        $routeFares = $this->loadFares($isAdmin, $assignedStationIds);
        $routes = $this->loadRoutes($isAdmin, $assignedStationModel?->id, $canSelectTripOrigin);
        $originStations = ($isAdmin
            ? Station::where('active', true)
            : Station::where('active', true)->whereIn('id', $assignedStationIds))
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'code']);

        $replicableTripsQuery = Trip::where('is_replicable', true);
        if (! $isAdmin) {
            $replicableTripsQuery
                ->whereIn('route_id', $routes->pluck('id'))
                ->whereIn('origin_station_id', $assignedStationIds);
        }

        $tripItems = $trips instanceof LengthAwarePaginator
            ? $trips->getCollection()
            : collect($trips);
        $colorTrip = $selectedTripId
            ? $tripItems->firstWhere('id', $selectedTripId)
            : $tripItems->first();
        $assignedStationColor = $this->resolveAssignedStationColor($colorTrip, $assignedStationModel?->id);

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
            'assignedStationIds' => $assignedStationIds,
            'assignedStationId' => $assignedStationModel?->id,
            'assignedStation' => $assignedStation,
            'canSelectTripOrigin' => $canSelectTripOrigin,
            'originStations' => $originStations,
            'assignedStationColor' => $assignedStationColor,
            'okohiIntegrationActive' => TicketSetting::getSettings()->hasOkohiIntegration(),
            'replicableTrips' => $replicableTripsQuery
                ->get(['id', 'route_id', 'origin_station_id', 'destination_station_id', 'departure_at', 'allows_open_connections', 'automatic_connection_allocation', 'code'])
                ->map(function ($trip) {
                    return [
                        'id' => $trip->id,
                        'route_id' => $trip->route_id,
                        'origin_station_id' => $trip->origin_station_id,
                        'destination_station_id' => $trip->destination_station_id,
                        'time' => $trip->departure_at ? $trip->departure_at->format('H:i') : '00:00',
                        'allows_open_connections' => (bool) $trip->allows_open_connections,
                        'automatic_connection_allocation' => $trip->automatic_connection_allocation,
                        'code' => $trip->code,
                    ];
                })
                ->unique(fn ($item) => $item['route_id'].'-'.$item['origin_station_id'].'-'.$item['destination_station_id'].'-'.$item['time'])
                ->values(),
        ];
    }

    private function resolveAssignedStationColor(?Trip $trip, ?string $stationId): ?array
    {
        if (! $trip || ! $stationId) {
            return null;
        }

        $stationIndex = app(TripSegmentService::class)->stationIndices($trip)[$stationId] ?? null;
        if ($stationIndex === null) {
            return null;
        }

        $hues = [220, 270, 25, 165, 330, 195, 140, 350];
        $hue = $hues[$stationIndex % count($hues)];

        return [
            'bg' => "hsl({$hue}, 80%, 75%)",
            'fg' => '#0F172A',
            'muted' => 'rgba(15,23,42,0.65)',
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
                        // Future trips remain visible at every station on the
                        // route, independently of the simultaneous-sale mode.
                        $scheduled->where('departure_at', '>=', $windowStart)
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
                $indices = app(TripSegmentService::class)->stationIndices($trip);
                $originIndex = $indices[$trip->origin_station_id] ?? null;
                if ($originIndex !== null) {
                    $destinationIds = collect($indices)
                        ->filter(fn ($index) => $index > $originIndex)
                        ->keys()
                        ->all();
                    $hasTransit = TicketConnection::where('transfer_station_id', $trip->origin_station_id)
                        ->whereIn('destination_station_id', $destinationIds)
                        ->whereIn('status', ['pending', 'ready'])
                        ->exists();
                } else {
                    $hasTransit = false;
                }
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
            $indices = app(TripSegmentService::class)->stationIndices($trip);
            $originIndex = $indices[$trip->origin_station_id] ?? null;
            if ($originIndex !== null) {
                $destinationIds = collect($indices)
                    ->filter(fn ($index) => $index > $originIndex)
                    ->keys()
                    ->all();
                $hasTransit = TicketConnection::where('transfer_station_id', $trip->origin_station_id)
                    ->whereIn('destination_station_id', $destinationIds)
                    ->whereIn('status', ['pending', 'ready'])
                    ->exists();
            } else {
                $hasTransit = false;
            }
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

    private function loadRoutes(bool $isAdmin, ?string $assignedStationId = null, bool $canSelectTripOrigin = false)
    {
        $stationRelations = [
            'originStation:id,name,code',
            'destinationStation:id,name,code',
            'routeStopOrders:id,route_id,station_id,stop_index',
            'routeStopOrders.station:id,name,code',
        ];

        if ($canSelectTripOrigin) {
            return ($isAdmin ? Route::query() : auth()->user()->accessibleRoutesQuery())
                ->where('active', true)
                ->with($stationRelations)
                ->orderBy('name')
                ->get();
        }

        return auth()->user()->accessibleRoutesQuery()
            ->with($stationRelations)
            ->orderBy('name')
            ->get()
            ->map(function (Route $route) use ($assignedStationId) {
                $orderedStations = collect([$route->originStation])
                    ->concat($route->routeStopOrders->sortBy('stop_index')->pluck('station'))
                    ->push($route->destinationStation)
                    ->filter()
                    ->unique('id')
                    ->values();

                $creationOrigin = $orderedStations->firstWhere('id', $assignedStationId);
                if (! $creationOrigin) {
                    return null;
                }

                $creationDestination = $creationOrigin->id === $orderedStations->last()?->id
                    ? $orderedStations->first()
                    : $orderedStations->last();

                if (! $creationDestination || $creationDestination->id === $creationOrigin->id) {
                    return null;
                }

                $route->setAttribute('creation_origin_station', $creationOrigin);
                $route->setAttribute('creation_destination_station', $creationDestination);
                $route->setAttribute('display_name', $creationOrigin->name.' → '.$creationDestination->name);

                return $route;
            })
            ->filter()
            ->values();
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
                        ->where('status', 'issued')
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
            'station_id' => ['nullable', 'uuid', 'exists:stations,id'],
        ]);

        $user = $request->user();
        $target = TripStatus::normalize($validated['status']);
        $progression = app(TripStationProgression::class);
        $activeSalesStationId = $progression->activeSalesStationId($trip);
        $departureStationId = $validated['station_id'] ?? $activeSalesStationId;

        if ($user?->role === 'seller') {
            $routeStationIds = array_keys(app(TripSegmentService::class)->stationIndices($trip));
            $hasLocalAccess = array_intersect($user->getActiveStationIds(), $routeStationIds) !== [];
            abort_unless($hasLocalAccess, 403, 'Ce voyage ne dessert aucune de vos gares affectées.');

            if ($target === 'departed') {
                abort_unless(
                    $departureStationId === $activeSalesStationId
                    && in_array($departureStationId, $user->getActiveStationIds(), true),
                    403,
                    'Seule la gare qui a actuellement la main peut enregistrer son départ.',
                );
            } else {
                abort_unless(
                    in_array($target, ['scheduled', 'boarding', 'delayed'], true),
                    403,
                    'Un vendeur ne peut pas déclarer seul l’arrivée ou l’annulation globale.',
                );
            }
        }

        try {
            if ($target === 'departed' && $trip->status === 'departed') {
                $trip = $progression->advance($trip, $departureStationId);
            } else {
                $trip = app(TripStateMachine::class)->transition(
                    $trip,
                    $validated['status'],
                    $request->user(),
                    'seller_web',
                    $validated['reason'] ?? null,
                );
            }
        } catch (InvalidTripTransition|\DomainException $exception) {
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
                $departureStationId
            ));
        } catch (\Exception $e) {
            Log::warning('Échec broadcast SeatMapUpdated: '.$e->getMessage());
        }

        return redirect()->back()->with('status', 'Statut du voyage mis à jour.');
    }

    public function updateTrip(Request $request, Trip $trip)
    {
        $user = $request->user();
        $routeStationIds = array_keys(app(TripSegmentService::class)->stationIndices($trip));

        if ($user?->role === 'seller') {
            abort_unless(
                array_intersect($user->getActiveStationIds(), $routeStationIds) !== [],
                403,
                'Ce voyage ne dessert aucune de vos gares affectées.',
            );
            abort_unless(
                in_array($trip->status, ['scheduled', 'boarding', 'delayed'], true),
                403,
                'Ce voyage ne peut plus être modifié par un vendeur.',
            );
        }

        $validated = $request->validate([
            'route_id' => ['required', 'uuid', 'exists:routes,id'],
            'code' => ['nullable', 'string', 'max:255'],
            'vehicle_id' => ['nullable', 'uuid', 'exists:vehicles,id'],
            'departure_at' => ['required', 'date'],
            'sales_control' => ['required', 'in:open,closed'],
            'allows_open_connections' => ['required', 'boolean'],
            'automatic_connection_allocation' => ['nullable', 'boolean'],
            'is_replicable' => ['nullable', 'boolean'],
        ]);

        abort_unless(
            $user->accessibleRoutesQuery()->whereKey($validated['route_id'])->exists(),
            403,
            'Vous n’avez pas accès à ce trajet.',
        );

        if ($trip->tickets()->where('status', 'issued')->exists()) {
            if ($validated['route_id'] !== $trip->route_id || ($validated['vehicle_id'] ?? null) !== $trip->vehicle_id) {
                return back()->withErrors([
                    'route_id' => 'Le trajet et le véhicule ne peuvent plus être changés après la première vente.',
                ]);
            }
        }

        if (! $validated['allows_open_connections']) {
            $validated['automatic_connection_allocation'] = null;
        }

        $validated['is_replicable'] = (bool) ($validated['is_replicable'] ?? false);
        $trip->update($validated);
        app(TripTimingService::class)->syncPlannedTimes($trip);

        try {
            SeatMapUpdated::dispatch(
                $trip->fresh(['route.routeStopOrders.station', 'originStation', 'destinationStation', 'vehicle.vehicleType']),
                [],
                'trip.updated',
                $trip->origin_station_id,
            );
        } catch (\Throwable $exception) {
            Log::warning('Échec de diffusion du voyage modifié.', [
                'trip_id' => $trip->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->back()->with('status', 'Voyage modifié avec succès.');
    }

    public function assignVehicle(Request $request, Trip $trip)
    {
        $user = $request->user();
        $routeStationIds = array_keys(app(TripSegmentService::class)->stationIndices($trip));

        if (in_array($user?->role, ['seller', 'supervisor'], true)) {
            abort_unless(
                array_intersect($user->getActiveStationIds(), $routeStationIds) !== [],
                403,
                'Ce voyage ne dessert aucune de vos gares affectées.',
            );
        }

        abort_unless(
            $user->accessibleRoutesQuery()->whereKey($trip->route_id)->exists(),
            403,
            'Vous n’avez pas accès à ce trajet.',
        );

        abort_if(
            ! in_array($trip->status, ['scheduled', 'boarding', 'delayed'], true),
            422,
            'Un véhicule ne peut plus être assigné à ce voyage.',
        );

        $validated = $request->validate([
            'vehicle_id' => [
                'required',
                'uuid',
                Rule::exists('vehicles', 'id')->where('active', true),
            ],
        ]);

        $poolStationId = $trip->origin_station_id ?: $trip->route?->origin_station_id;
        abort_unless($poolStationId, 422, 'La gare de départ de ce voyage est introuvable.');

        if (! $user->isAdmin()) {
            $belongsToStationPool = StationVehicleAssignment::query()
                ->where('station_id', $poolStationId)
                ->where('vehicle_id', $validated['vehicle_id'])
                ->activeOn($trip->departure_at)
                ->exists();

            abort_unless(
                $belongsToStationPool,
                422,
                'Ce véhicule ne fait pas partie du pool disponible pour cette gare à la date du voyage.',
            );
        }

        $assigned = Trip::query()
            ->whereKey($trip->id)
            ->whereNull('vehicle_id')
            ->update(['vehicle_id' => $validated['vehicle_id']]);

        abort_if(
            $assigned === 0,
            409,
            'Un véhicule est déjà assigné à ce voyage.',
        );

        $trip->refresh()->load('vehicle.vehicleType');

        try {
            SeatMapUpdated::dispatch(
                $trip->fresh(['route.routeStopOrders.station', 'originStation', 'destinationStation', 'vehicle.vehicleType']),
                [],
                'trip.vehicle_assigned',
                $trip->origin_station_id,
            );
        } catch (\Throwable $exception) {
            Log::warning('Échec de diffusion de l’assignation du véhicule.', [
                'trip_id' => $trip->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Véhicule assigné avec succès.',
            'trip' => $trip,
        ]);
    }

    public function availableVehicles(Request $request, Trip $trip)
    {
        $user = $request->user();
        $trip->loadMissing(['route', 'originStation']);
        $poolStationId = $trip->origin_station_id ?: $trip->route?->origin_station_id;
        abort_unless($poolStationId, 422, 'La gare de départ de ce voyage est introuvable.');

        if (in_array($user?->role, ['seller', 'supervisor'], true)) {
            abort_unless(
                in_array($poolStationId, $user->getActiveStationIds(), true),
                403,
                'Vous ne pouvez utiliser que le pool de votre gare.',
            );
        }

        abort_unless(
            $user->accessibleRoutesQuery()->whereKey($trip->route_id)->exists(),
            403,
            'Vous n’avez pas accès à ce trajet.',
        );

        $vehicles = Vehicle::with('vehicleType')
            ->where('active', true)
            ->whereHas('stationAssignments', fn ($assignments) => $assignments
                ->where('station_id', $poolStationId)
                ->activeOn($trip->departure_at))
            ->orderBy('identifier')
            ->get(['id', 'identifier', 'maker', 'seat_count', 'vehicle_type_id']);

        return response()->json([
            'station' => Station::find($poolStationId, ['id', 'name', 'city', 'code']),
            'date' => $trip->departure_at?->toDateString(),
            'vehicles' => $vehicles,
        ]);
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
                    $query->where('origin_station_id', $stationId)
                        ->orWhere('destination_station_id', $stationId)
                        ->orWhereHas('route', function ($routeQuery) use ($stationId) {
                            $routeQuery->where('origin_station_id', $stationId)
                                ->orWhere('destination_station_id', $stationId)
                                ->orWhereHas('routeStopOrders', fn ($stopQuery) => $stopQuery->where('station_id', $stationId));
                        });
                } else {
                    $stationMatches = fn ($stationQuery) => $stationQuery->where(
                        fn ($matchQuery) => $matchQuery
                            ->where('name', 'like', "%{$stationId}%")
                            ->orWhere('city', 'like', "%{$stationId}%")
                    );

                    $query->whereHas('originStation', $stationMatches)
                        ->orWhereHas('destinationStation', $stationMatches)
                        ->orWhereHas('route.routeStopOrders.station', $stationMatches);
                }
            });
        }

        $trips = $tripsQuery->get();

        $selectedStation = null;
        if ($stationId) {
            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $stationId);
            if ($isUuid) {
                $selectedStation = Station::find($stationId);
            } else {
                $selectedStation = Station::where('name', 'like', "%{$stationId}%")
                    ->orWhere('city', 'like', "%{$stationId}%")
                    ->first();
            }
        }

        if ($selectedStation) {
            $segments = app(TripSegmentService::class);
            $trips = $trips->filter(function ($trip) use ($selectedStation, $segments) {
                $indices = $segments->stationIndices($trip);

                return isset($indices[$selectedStation->id]);
            })->values();
        }

        $this->enrichTripsWithSeatCounts($trips);

        $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'city']);

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
