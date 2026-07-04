<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ManagesVehicleTypes;
use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetVehicleTypeController extends Controller
{
    use ManagesVehicleTypes;

    public function __construct(private SeatMapService $seatMapService)
    {
    }

    public function index()
    {
        $vehicleTypes = VehicleType::orderBy('name')->paginate(20);

        return Inertia::render('Fleet/VehicleTypes/Index', [
            'vehicleTypes' => $vehicleTypes,
        ]);
    }

    public function create()
    {
        return Inertia::render('Fleet/VehicleTypes/Form');
    }

    public function store(Request $request)
    {
        $this->performStoreVehicleType($request, $this->seatMapService);

        return redirect()->route('fleet.vehicle-types.index')->with('success', 'Type de véhicule enregistré.');
    }

    public function edit(VehicleType $vehicleType)
    {
        return Inertia::render('Fleet/VehicleTypes/Form', [
            'vehicleType' => $vehicleType,
        ]);
    }

    public function update(Request $request, VehicleType $vehicleType)
    {
        $this->performUpdateVehicleType($request, $vehicleType, $this->seatMapService);

        return redirect()->route('fleet.vehicle-types.index')->with('success', 'Type de véhicule mis à jour.');
    }

    public function destroy(VehicleType $vehicleType)
    {
        $this->performDestroyVehicleType($vehicleType);

        return back()->with('success', 'Type de véhicule supprimé.');
    }
}
