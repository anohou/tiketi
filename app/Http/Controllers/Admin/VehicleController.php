<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ManagesVehicles;
use App\Models\CrewMember;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VehicleController extends Controller
{
    use ManagesVehicles;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $statusFilter = $request->query('operational_status');

        $vehicles = Vehicle::with([
            'vehicleType',
            'trips.route',
            'currentCrew.crewMember',
            'currentStationAssignment.station',
        ])
            ->withCount('trips')
            ->orderBy('identifier')
            ->paginate(50);

        $service = app(\App\Services\VehicleOperationalStatusService::class);
        $operationalMap = $service->mapForVehicles($vehicles->getCollection());

        $vehicles->through(function (Vehicle $vehicle) use ($operationalMap, $service) {
            $vehicle->setAttribute('operational', $operationalMap[$vehicle->id] ?? $service->forVehicle($vehicle));

            return $vehicle;
        });

        if ($statusFilter) {
            $vehicles->setCollection(
                $vehicles->getCollection()->filter(function (Vehicle $vehicle) use ($statusFilter) {
                    $op = $vehicle->getAttribute('operational');
                    return ($op['status'] ?? 'available') === $statusFilter;
                })->values()
            );
        }

        $operationalSummary = $service->summaryForVehicles(
            Vehicle::query()->where('is_placeholder', false)->get(['id', 'active', 'inactive_reason'])
        );

        $vehicleTypes = VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']);

        $crewMembers = CrewMember::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'phone', 'license_number']);

        return Inertia::render('Admin/Vehicles/Index', [
            'vehicles' => $vehicles,
            'vehicleTypes' => $vehicleTypes,
            'crewMembers' => $crewMembers,
            'operationalSummary' => $operationalSummary,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Vehicles/Form', [
            'vehicleTypes' => VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->performStoreVehicle($request);

        return redirect()->route('admin.vehicles.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(Vehicle $vehicle)
    {
        return redirect()->route('admin.vehicles.edit', $vehicle);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Vehicle $vehicle)
    {
        return Inertia::render('Admin/Vehicles/Form', [
            'vehicle' => $vehicle,
            'vehicleTypes' => VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Vehicle $vehicle)
    {
        $this->performUpdateVehicle($request, $vehicle);

        return redirect()->route('admin.vehicles.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Vehicle $vehicle)
    {
        $this->performDestroyVehicle($vehicle);

        return back();
    }
}
