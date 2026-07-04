<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ManagesVehicleTypes;
use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VehicleTypeController extends Controller
{
    use ManagesVehicleTypes;

    protected $seatMapService;

    public function __construct(SeatMapService $seatMapService)
    {
        $this->seatMapService = $seatMapService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $vehicleTypes = VehicleType::orderBy('name')->paginate(20);

        return Inertia::render('Admin/VehicleTypes/Index', [
            'vehicleTypes' => $vehicleTypes,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/VehicleTypes/Form');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->performStoreVehicleType($request, $this->seatMapService);

        return redirect()->route('admin.vehicle-types.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(VehicleType $vehicleType)
    {
        return redirect()->route('admin.vehicle-types.edit', $vehicleType);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(VehicleType $vehicleType)
    {
        return Inertia::render('Admin/VehicleTypes/Form', [
            'vehicleType' => $vehicleType,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, VehicleType $vehicleType)
    {
        $this->performUpdateVehicleType($request, $vehicleType, $this->seatMapService);

        return redirect()->route('admin.vehicle-types.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VehicleType $vehicleType)
    {
        $this->performDestroyVehicleType($vehicleType);

        return back();
    }
}
