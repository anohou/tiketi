<?php

namespace App\Http\Controllers\Traits;

use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Http\Request;

trait ManagesVehicleTypes
{
    protected function validateVehicleType(Request $request, ?VehicleType $vehicleType = null): array
    {
        return $request->validate([
            'name' => 'required|string|unique:vehicle_types,name'.($vehicleType ? ','.$vehicleType->id : ''),
            'seat_count' => 'required|integer|min:1',
            'seat_configuration' => 'required|string',
            'door_positions' => 'nullable|array',
            'door_positions.*' => 'integer',
            'door_side' => 'nullable|string|in:left,right',
            'door_width' => 'nullable|integer|min:1|max:3',
            'last_row_seats' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
        ]);
    }

    protected function performStoreVehicleType(Request $request, SeatMapService $seatMapService): VehicleType
    {
        $data = $this->validateVehicleType($request);
        $data['seat_map'] = $seatMapService->generateSeatMap($data);

        return VehicleType::create($data);
    }

    protected function performUpdateVehicleType(Request $request, VehicleType $vehicleType, SeatMapService $seatMapService): bool
    {
        $data = $this->validateVehicleType($request, $vehicleType);
        $data['seat_map'] = $seatMapService->generateSeatMap($data);

        return $vehicleType->update($data);
    }

    protected function performDestroyVehicleType(VehicleType $vehicleType): ?bool
    {
        return $vehicleType->delete();
    }
}
