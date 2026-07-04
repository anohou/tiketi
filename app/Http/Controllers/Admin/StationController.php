<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $stations = Station::with([
            'destination', // Eager load destination
            'userAssignments.user',
            'routeStopOrders.route.originStation',
            'routeStopOrders.route.destinationStation',
            'routeStopOrders.route.routeStopOrders.station',
            'routeStopOrders.station',
            'originRoutes.originStation',
            'originRoutes.destinationStation',
            'originRoutes.routeStopOrders.station',
            'destinationRoutes.originStation',
            'destinationRoutes.destinationStation',
            'destinationRoutes.routeStopOrders.station',
        ])
            ->withCount(['userAssignments'])
            ->orderBy('name')
            ->paginate(50);

        $destinations = \App\Models\Destination::with([
            'stations:id,name,code,city,settings,destination_id',
        ])->orderBy('name')->get(['id', 'name', 'settings']);

        return Inertia::render('Admin/Stations/Index', [
            'stations' => $stations,
            'destinations' => $destinations,
        ]);
    }

    // ... create() ...

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string|unique:stations,code',
            'destination_id' => 'required|exists:destinations,id',
            'city' => 'nullable|string', // Legacy? Or keeps for detailed address?
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'active' => 'boolean',
            'can_sell_tickets' => 'boolean',
        ]);

        $settings = Arr::wrap($data['settings'] ?? []);
        $settings['gps'] = [
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
        $settings['can_sell_tickets'] = $data['can_sell_tickets'] ?? true;
        $data['settings'] = $settings;
        unset($data['latitude'], $data['longitude'], $data['can_sell_tickets']);

        // Auto-fill city name from destination if empty?
        if (empty($data['city'])) {
            $dest = \App\Models\Destination::find($data['destination_id']);
            $data['city'] = $dest->name;
        }

        Station::create($data);

        return back()->with('success', 'Gare créée avec succès.'); // Redirect back better for modals
    }

    // ... edit() ...

    public function update(Request $request, Station $station)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string|unique:stations,code,'.$station->id.',id',
            'destination_id' => 'required|exists:destinations,id',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'active' => 'boolean',
            'can_sell_tickets' => 'boolean',
        ]);

        $settings = Arr::wrap($station->settings ?? []);
        $settings['gps'] = [
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
        $settings['can_sell_tickets'] = $data['can_sell_tickets'] ?? $station->can_sell_tickets ?? true;
        $data['settings'] = $settings;
        unset($data['latitude'], $data['longitude'], $data['can_sell_tickets']);

        if (empty($data['city'])) {
            $dest = \App\Models\Destination::find($data['destination_id']);
            $data['city'] = $dest->name;
        }

        $station->update($data);

        return back()->with('success', 'Gare mise à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Station $station)
    {
        $station->delete();

        return back();
    }
}
