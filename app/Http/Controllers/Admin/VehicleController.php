<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VehicleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $vehicles = Vehicle::with(['vehicleType', 'trips.route'])
            ->withCount('trips')
            ->orderBy('identifier')
            ->paginate(50);
        $vehicleTypes = VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']);
        return Inertia::render('Admin/Vehicles/Index', [
            'vehicles' => $vehicles,
            'vehicleTypes' => $vehicleTypes,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Vehicles/Form', [
            'vehicleTypes' => VehicleType::orderBy('name')->get(['id', 'name', 'seat_count'])
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'identifier' => 'required|string|unique:vehicles,identifier',
            'maker' => 'nullable|string|max:255',
            'vehicle_type_id' => 'required|uuid|exists:vehicle_types,id',
            'seat_count' => 'required|integer|min:1',
            'active' => 'boolean',
            'inactive_reason' => 'nullable|string|required_if:active,false',
        ]);
        Vehicle::create($data);
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
            'vehicleTypes' => VehicleType::orderBy('name')->get(['id', 'name', 'seat_count'])
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate([
            'identifier' => 'required|string|max:255|unique:vehicles,identifier,' . ($vehicle->id ?? ''),
            'maker' => 'nullable|string|max:255',
            'vehicle_type_id' => 'required|exists:vehicle_types,id',
            'seat_count' => 'required|integer|min:1',
            'active' => 'boolean',
            'inactive_reason' => 'nullable|string|required_if:active,false',
        ]);
        $vehicle->update($data);
        return redirect()->route('admin.vehicles.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();
        return back();
    }
}
