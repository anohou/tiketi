<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TripController extends Controller
{
    /**
     * Get routes accessible to the current user.
     * For bidirectional routes, a seller can access routes where their assigned station
     * is either the origin OR the destination.
     */
    private function getAccessibleRoutesQuery()
    {
        $user = auth()->user();

        if ($user->role === 'admin') {
            return Route::query();
        }

        $stationIds = $user->stationAssignments()->pluck('station_id');

        // Allow access to routes where the user's station is origin OR destination
        return Route::where(function ($query) use ($stationIds) {
            $query->whereIn('origin_station_id', $stationIds)
                ->orWhereIn('destination_station_id', $stationIds);
        });
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $trips = Trip::with(['route.originStation', 'route.destinationStation', 'route.routeStopOrders', 'vehicle', 'tickets.toStation'])
            ->withCount(['tickets', 'tripSeatOccupancies as occupied_seats'])
            ->orderBy('departure_at', 'desc')
            ->paginate(20);

        $routes = $this->getAccessibleRoutesQuery()
            ->with(['originStation', 'destinationStation'])
            ->orderBy('name')
            ->get();

        $vehicles = Vehicle::orderBy('identifier')->get(['id', 'identifier']);

        return Inertia::render('Admin/Trips/Index', [
            'trips' => $trips,
            'routes' => $routes,
            'vehicles' => $vehicles,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $routes = $this->getAccessibleRoutesQuery()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Admin/Trips/Form', [
            'routes' => $routes,
            'vehicles' => Vehicle::orderBy('identifier')->get(['id', 'identifier']),
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
                    $exists = $this->getAccessibleRoutesQuery()->where('id', $value)->exists();
                    if (! $exists) {
                        $fail('Vous n\'avez pas accès à cet itinéraire (station non assignée).');
                    }
                },
            ],
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'departure_at' => 'required|date',
            'status' => 'nullable|in:scheduled,boarding,departed,arrived,cancelled',
            'sales_control' => 'nullable|in:open,closed',
        ]);

        // Set default status if not provided
        $data['status'] = $data['status'] ?? 'scheduled';
        $data['sales_control'] = $data['sales_control'] ?? 'closed';

        // Determine trip origin and destination based on seller's station
        $route = Route::find($data['route_id']);

        if ($user->role === 'admin') {
            // Admins create trips in the route's default direction
            $data['origin_station_id'] = $route->origin_station_id;
            $data['destination_station_id'] = $route->destination_station_id;
        } else {
            // For sellers/supervisors, check their assigned stations
            $assignedStationIds = \App\Models\UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->pluck('station_id')
                ->toArray();

            // If seller's station is the route's destination (but not origin), reverse the direction
            $isReversed = in_array($route->destination_station_id, $assignedStationIds)
                && ! in_array($route->origin_station_id, $assignedStationIds);

            if ($isReversed) {
                // Seller is at destination, so trip goes: destination -> origin
                $data['origin_station_id'] = $route->destination_station_id;
                $data['destination_station_id'] = $route->origin_station_id;
            } else {
                // Normal direction
                $data['origin_station_id'] = $route->origin_station_id;
                $data['destination_station_id'] = $route->destination_station_id;
            }
        }

        $trip = Trip::create($data);

        \App\Events\TripCreated::dispatch($trip);

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
            'vehicles' => Vehicle::orderBy('identifier')->get(['id', 'identifier']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Trip $trip)
    {
        $data = $request->validate([
            'route_id' => 'required|uuid|exists:routes,id',
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'departure_at' => 'required|date',
            'status' => 'required|in:scheduled,boarding,departed,arrived,cancelled',
        ]);
        $trip->update($data);

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
}
