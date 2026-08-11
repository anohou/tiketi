<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepartureSchedule;
use App\Models\Destination;
use App\Models\Route as BusRoute;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\VehicleOperationalStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class StationController extends Controller
{
    public function index()
    {
        $query = Station::with([
            'destination', // Eager load destination
            'userAssignments.user',
            'vehicleAssignments.vehicle.vehicleType',
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
        ])->withCount(['userAssignments', 'vehicleAssignments']);

        if (auth()->user()->role === 'supervisor') {
            $query->whereIn('id', auth()->user()->getActiveStationIds());
        }

        $stations = $query->orderBy('name')
            ->paginate(50);

        $destinations = Destination::with([
            'stations:id,name,code,city,settings,destination_id',
        ])->orderBy('name')->get(['id', 'name', 'settings']);

        $service = app(VehicleOperationalStatusService::class);
        $vehicles = Vehicle::with(['vehicleType', 'currentStationAssignment.station'])
            ->where('active', true)
            ->where('is_placeholder', false)
            ->orderBy('identifier')
            ->get();

        $operationalMap = $service->mapForVehicles($vehicles);
        $vehicles->each(function (Vehicle $v) use ($operationalMap) {
            $v->setAttribute('operational', $operationalMap[$v->id] ?? null);
        });

        $departureSchedules = DepartureSchedule::with([
            'route.originStation',
            'route.destinationStation',
            'originStation:id,name',
            'destinationStation:id,name',
        ])
            ->orderBy('departure_time')
            ->get();

        return Inertia::render('Admin/Stations/Index', [
            'stations' => $stations,
            'stationOptions' => Station::where('active', true)->orderBy('name')->get(['id', 'name', 'city', 'destination_id']),
            'destinations' => $destinations,
            'vehicles' => $vehicles,
            'departureSchedules' => $departureSchedules,
            'routeOptions' => BusRoute::where('active', true)
                ->with(['originStation:id,name', 'destinationStation:id,name', 'routeStopOrders.station:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'origin_station_id', 'destination_station_id']),
            'vehicleTypes' => VehicleType::where('active', true)->orderBy('name')->get(['id', 'name', 'seat_count']),
            'sellerOptions' => User::where('active', true)
                ->where('role', 'seller')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    // ... create() ...

    public function store(Request $request)
    {
        if (auth()->user()->role === 'supervisor') {
            abort(403, 'Unauthorized action.');
        }

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

        $destination = Destination::withCount('stations')->findOrFail($data['destination_id']);
        $hasStationCoordinates = ($data['latitude'] ?? null) !== null
            || ($data['longitude'] ?? null) !== null;
        $destinationHasCoordinates = $destination->latitude !== null
            && $destination->longitude !== null;

        if (! $hasStationCoordinates
            && $destination->stations_count === 0
            && $destinationHasCoordinates) {
            $data['latitude'] = $destination->latitude;
            $data['longitude'] = $destination->longitude;
        }

        $settings = Arr::wrap($data['settings'] ?? []);
        $settings['gps'] = [
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
        $settings['can_sell_tickets'] = $data['can_sell_tickets'] ?? true;
        $data['settings'] = $settings;
        unset($data['latitude'], $data['longitude'], $data['can_sell_tickets']);

        if (empty($data['city'])) {
            $data['city'] = $destination->name;
        }

        Station::create($data);

        return back()->with('success', 'Gare créée avec succès.'); // Redirect back better for modals
    }

    // ... edit() ...

    public function update(Request $request, Station $station)
    {
        if (auth()->user()->role === 'supervisor') {
            abort(403, 'Unauthorized action.');
        }

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

        $destination = Destination::findOrFail($data['destination_id']);
        $hasStationCoordinates = ($data['latitude'] ?? null) !== null
            || ($data['longitude'] ?? null) !== null;
        $destinationHasCoordinates = $destination->latitude !== null
            && $destination->longitude !== null;
        $willBeDestinationOnlyStation = ! $destination->stations()
            ->whereKeyNot($station->id)
            ->exists();

        if (! $hasStationCoordinates
            && $willBeDestinationOnlyStation
            && $destinationHasCoordinates) {
            $data['latitude'] = $destination->latitude;
            $data['longitude'] = $destination->longitude;
        }

        $settings = Arr::wrap($station->settings ?? []);
        $settings['gps'] = [
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];
        $settings['can_sell_tickets'] = $data['can_sell_tickets'] ?? $station->can_sell_tickets ?? true;
        $data['settings'] = $settings;
        unset($data['latitude'], $data['longitude'], $data['can_sell_tickets']);

        if (empty($data['city'])) {
            $data['city'] = $destination->name;
        }

        $station->update($data);

        return back()->with('success', 'Gare mise à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Station $station)
    {
        if (auth()->user()->role === 'supervisor') {
            abort(403, 'Unauthorized action.');
        }

        $station->delete();

        return back();
    }
}
