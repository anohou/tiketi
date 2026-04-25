<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route as BusRoute;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $routes = BusRoute::with([
            'originDestination',
            'targetDestination',
            'originStation', // Optional
            'destinationStation', // Optional
            'routeStopOrders.station', // Changed from stop.station
            'trips.vehicle',
        ])
            ->withCount(['trips', 'routeStopOrders'])
            ->orderBy('name')
            ->paginate(50);

        $destinations = \App\Models\Destination::orderBy('name')->get(['id', 'name']);

        // Provide all stations for intermediate stops selection
        $stations = Station::with('destination')->orderBy('name')->get()->map(function ($station) {
            return [
                'id' => $station->id,
                'name' => $station->name,
                'city' => $station->destination ? $station->destination->name : $station->city,
                'destination_id' => $station->destination_id,
            ];
        });

        // Get all fares
        $fares = \App\Models\RouteFare::with(['fromStation', 'toStation'])->get(); // Changed relations

        return Inertia::render('Admin/Routes/Index', [
            'routes' => $routes,
            'destinations' => $destinations,
            'stations' => $stations,
            // 'stops' removed as concept
            'fares' => $fares,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Routes/Form', [
            'destinations' => \App\Models\Destination::orderBy('name')->get(),
            'stations' => Station::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'origin_destination_id' => 'required|uuid|exists:destinations,id',
            'target_destination_id' => 'required|uuid|exists:destinations,id',
            'active' => 'boolean',
        ]);

        BusRoute::create($data);

        return redirect()->route('admin.routes.index');
    }

    public function show(BusRoute $route)
    {
        return redirect()->route('admin.routes.edit', $route);
    }

    public function edit(BusRoute $route)
    {
        return Inertia::render('Admin/Routes/Form', [
            'routeItem' => $route,
            'destinations' => \App\Models\Destination::orderBy('name')->get(),
            'stations' => Station::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, BusRoute $route)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'origin_destination_id' => 'required|uuid|exists:destinations,id',
            'target_destination_id' => 'required|uuid|exists:destinations,id',
            'active' => 'boolean',
        ]);

        $route->update($data);

        return redirect()->route('admin.routes.index');
    }

    public function destroy(BusRoute $route)
    {
        $route->delete();

        return back();
    }
}
