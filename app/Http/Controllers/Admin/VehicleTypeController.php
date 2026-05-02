<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VehicleTypeController extends Controller
{
    protected $seatMapService;

    public function __construct(\App\Services\SeatMapService $seatMapService)
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
        $data = $request->validate([
            'name' => 'required|string|unique:vehicle_types,name',
            'seat_count' => 'required|integer|min:1',
            'seat_configuration' => 'required|string', // e.g., "2+2"
            'door_positions' => 'nullable|array', // e.g., [1, 23, 24]
            'door_positions.*' => 'integer',
            'door_side' => 'nullable|string|in:left,right',
            'door_width' => 'nullable|integer|min:1|max:3',
            'last_row_seats' => 'nullable|integer|min:1',
            'active' => 'nullable|boolean',
        ]);
        $data['seat_map'] = $this->seatMapService->generateSeatMap($data);
        VehicleType::create($data);

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
        $data = $request->validate([
            'name' => 'required|string|unique:vehicle_types,name,'.$vehicleType->id.',id',
            'seat_count' => 'required|integer|min:1',
            'seat_configuration' => 'required|string',
            'door_positions' => 'nullable|array',
            'door_positions.*' => 'integer',
            'door_side' => 'nullable|string|in:left,right',
            'door_width' => 'nullable|integer|min:1|max:3',
            'last_row_seats' => 'nullable|integer|min:1',
            'active' => 'nullable|boolean',
        ]);
        $data['seat_map'] = $this->seatMapService->generateSeatMap($data);
        $vehicleType->update($data);

        return redirect()->route('admin.vehicle-types.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VehicleType $vehicleType)
    {
        $vehicleType->delete();

        return back();
    }
}
