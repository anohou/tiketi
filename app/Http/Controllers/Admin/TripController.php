<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Trips\InvalidTripTransition;
use App\Domain\Trips\TripStateMachine;
use App\Events\TripCreated;
use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Services\AutomaticConnectionAllocator;
use App\Services\TripTimingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TripController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $trips = Trip::with(['route.originStation', 'route.destinationStation', 'route.routeStopOrders', 'vehicle', 'tickets.toStation'])
            ->withCount(['tickets as tickets_count' => function ($q) {
                $q->where('status', '!=', 'cancelled');
            }])
            ->upcomingFirst()
            ->paginate(20);

        // Charger l'équipage pour chaque voyage (via les affectations véhicule à la date de départ) sans requête N+1
        $vehicleIds = $trips->pluck('vehicle_id')->unique()->filter()->toArray();
        if (! empty($vehicleIds)) {
            $minDate = $trips->min('departure_at');
            $maxDate = $trips->max('departure_at');

            $assignments = VehicleCrewAssignment::whereIn('vehicle_id', $vehicleIds)
                ->where(function ($query) use ($minDate) {
                    $query->whereNull('assigned_to')
                        ->orWhere('assigned_to', '>', $minDate);
                })
                ->where('assigned_from', '<=', $maxDate)
                ->with('crewMember')
                ->get();

            $trips->getCollection()->transform(function ($trip) use ($assignments) {
                $tripCrew = $assignments->filter(function ($assignment) use ($trip) {
                    return $assignment->vehicle_id === $trip->vehicle_id
                        && $assignment->assigned_from <= $trip->departure_at
                        && (is_null($assignment->assigned_to) || $assignment->assigned_to > $trip->departure_at);
                });

                $trip->crew_info = $tripCrew->map(fn ($assignment) => [
                    'role' => $assignment->role,
                    'crew_member' => $assignment->crewMember ? [
                        'id' => $assignment->crewMember->id,
                        'name' => $assignment->crewMember->name,
                        'phone' => $assignment->crewMember->phone,
                        'role' => $assignment->crewMember->role,
                    ] : null,
                ])->values();

                return $trip;
            });
        } else {
            $trips->getCollection()->transform(function ($trip) {
                $trip->crew_info = collect();

                return $trip;
            });
        }

        $routes = auth()->user()->accessibleRoutesQuery()
            ->with(['originStation', 'destinationStation'])
            ->orderBy('name')
            ->get();

        $vehicles = Vehicle::where('active', true)->orderBy('identifier')->get(['id', 'identifier']);

        return Inertia::render('Admin/Trips/Index', [
            'trips' => $trips,
            'routes' => $routes,
            'vehicles' => $vehicles,
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
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $routes = auth()->user()->accessibleRoutesQuery()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Admin/Trips/Form', [
            'routes' => $routes,
            'vehicles' => Vehicle::where('active', true)->orderBy('identifier')->get(['id', 'identifier']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'route_id' => [
                'required',
                'uuid',
                'exists:routes,id',
                function ($attribute, $value, $fail) {
                    // Check if user has access to this route
                    $exists = auth()->user()->accessibleRoutesQuery()->where('id', $value)->exists();
                    if (! $exists) {
                        $fail('Vous n\'avez pas accès à cet itinéraire (station non assignée).');
                    }
                },
            ],
            'vehicle_id' => 'nullable|uuid|exists:vehicles,id',
            'departure_at' => 'required|date',
            'status' => 'nullable|in:scheduled,boarding,departed,arrived,cancelled',
            'booking_type' => 'nullable|in:seat_assignment,bulk,semi_intelligent',
            'sales_control' => 'nullable|in:open,closed',
            'allows_open_connections' => 'nullable|boolean',
            'automatic_connection_allocation' => 'nullable|boolean',
            'is_replicable' => 'nullable|boolean',
        ]);

        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            if ($vehicle->isInsuranceExpired($data['departure_at'])) {
                return back()->withErrors([
                    'vehicle_id' => 'L\'assurance de ce véhicule est expirée à la date de départ du voyage ('
                        .$vehicle->insurance_expiry_date->format('d/m/Y').').',
                ]);
            }
        } else {
            $data['vehicle_id'] = null;
        }

        $data['status'] = $data['status'] ?? 'scheduled';
        $data['booking_type'] = $data['booking_type'] ?? 'seat_assignment';
        $data['sales_control'] = $data['sales_control'] ?? 'closed';
        $data['allows_open_connections'] = (bool) ($data['allows_open_connections'] ?? false);
        $data['is_replicable'] = (bool) ($data['is_replicable'] ?? false);

        // Determine trip origin and destination based on seller's station
        $route = Route::find($data['route_id']);

        [$defaultOriginStationId, $defaultDestinationStationId] = $this->resolveRouteTerminalStations($route);

        if (! $defaultOriginStationId || ! $defaultDestinationStationId) {
            return back()->withErrors([
                'route_id' => 'Cette route doit avoir au moins une gare de départ et une gare d’arrivée configurées.',
            ]);
        }

        if ($user->role === 'admin') {
            // Admins create trips in the route's default direction
            $data['origin_station_id'] = $defaultOriginStationId;
            $data['destination_station_id'] = $defaultDestinationStationId;
        } else {
            // For sellers/supervisors, check their assigned stations
            $assignedStationIds = $user->getActiveStationIds();

            // If seller's station is the route's destination (but not origin), reverse the direction
            $isReversed = in_array($defaultDestinationStationId, $assignedStationIds)
                && ! in_array($defaultOriginStationId, $assignedStationIds);

            if ($isReversed) {
                // Seller is at destination, so trip goes: destination -> origin
                $data['origin_station_id'] = $defaultDestinationStationId;
                $data['destination_station_id'] = $defaultOriginStationId;
            } else {
                // Normal direction
                $data['origin_station_id'] = $defaultOriginStationId;
                $data['destination_station_id'] = $defaultDestinationStationId;
            }
        }

        $trip = Trip::create($data);
        $trip = app(TripTimingService::class)->syncPlannedTimes($trip);

        app(AutomaticConnectionAllocator::class)->allocateForTrip($trip, $user);

        TripCreated::dispatch($trip);

        // Redirect based on user role
        if ($user->role === 'admin') {
            return redirect()->route('admin.trips.index')->with('success', 'Voyage créé avec succès!');
        }

        // Sellers and supervisors go back to ticketing with the new trip selected
        return redirect()->route('seller.ticketing', ['trip_id' => $trip->id])->with('success', 'Voyage créé avec succès!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Trip $trip)
    {
        return redirect()->route('admin.trips.edit', $trip);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Trip $trip)
    {
        return Inertia::render('Admin/Trips/Form', [
            'trip' => $trip,
            'routes' => Route::orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::where('active', true)->orderBy('identifier')->get(['id', 'identifier']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Trip $trip)
    {
        $data = $request->validate([
            'route_id' => 'required|uuid|exists:routes,id',
            'vehicle_id' => 'nullable|uuid|exists:vehicles,id',
            'departure_at' => 'required|date',
            'status' => 'required|in:scheduled,boarding,departed,arrived,cancelled,delayed',
            'booking_type' => 'nullable|in:seat_assignment,bulk,semi_intelligent',
            'sales_control' => 'nullable|in:open,closed',
            'allows_open_connections' => 'nullable|boolean',
            'automatic_connection_allocation' => 'nullable|boolean',
            'is_replicable' => 'nullable|boolean',
        ]);

        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            if ($vehicle->isInsuranceExpired($data['departure_at'])) {
                return back()->withErrors([
                    'vehicle_id' => 'L\'assurance de ce véhicule est expirée à la date de départ du voyage ('
                        .$vehicle->insurance_expiry_date->format('d/m/Y').').',
                ]);
            }
        } else {
            $data['vehicle_id'] = null;
        }
        $data['is_replicable'] = (bool) ($data['is_replicable'] ?? false);
        $requestedStatus = $data['status'];
        unset($data['status']);
        $previousStatus = $trip->status;
        $stateMachine = app(TripStateMachine::class);
        if ($requestedStatus !== $previousStatus && ! $stateMachine->can($previousStatus, $requestedStatus)) {
            return back()->withErrors([
                'status' => "Transition de voyage interdite : {$previousStatus} → {$requestedStatus}.",
            ])->withInput();
        }
        $trip->update($data);
        $trip = app(TripTimingService::class)->syncPlannedTimes($trip);
        if ($requestedStatus !== $previousStatus) {
            try {
                $stateMachine->transition(
                    $trip,
                    $requestedStatus,
                    $request->user(),
                    'admin_web',
                    $request->input('status_reason'),
                );
            } catch (InvalidTripTransition $exception) {
                return back()->withErrors(['status' => $exception->getMessage()])->withInput();
            }
        }

        return redirect()->route('admin.trips.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Trip $trip)
    {
        $trip->delete();

        return back();
    }

    private function resolveRouteTerminalStations(Route $route): array
    {
        if ($route->origin_station_id && $route->destination_station_id) {
            return [$route->origin_station_id, $route->destination_station_id];
        }

        $stationIds = $route->routeStopOrders()
            ->orderBy('stop_index')
            ->pluck('station_id')
            ->values();

        return [
            $route->origin_station_id ?? $stationIds->first(),
            $route->destination_station_id ?? ($stationIds->count() > 1 ? $stationIds->last() : $stationIds->first()),
        ];
    }
}
