<?php

namespace App\Http\Controllers\Traits;

use App\Models\Vehicle;
use Illuminate\Http\Request;

trait ManagesVehicles
{
    protected function validateVehicle(Request $request, ?Vehicle $vehicle = null): array
    {
        return $request->validate([
            'identifier' => 'required|string|max:255|unique:vehicles,identifier'.($vehicle ? ','.$vehicle->id : ''),
            'maker' => 'nullable|string|max:255',
            'vehicle_type_id' => 'required|uuid|exists:vehicle_types,id',
            'seat_count' => 'required|integer|min:1',
            'active' => 'boolean',
            'inactive_reason' => 'nullable|string|required_if:active,false',
            'insurance_expiry_date' => 'nullable|date',
        ]);
    }

    protected function performStoreVehicle(Request $request): Vehicle
    {
        $data = $this->validateVehicle($request);

        return Vehicle::create($data);
    }

    protected function performUpdateVehicle(Request $request, Vehicle $vehicle): bool
    {
        $data = $this->validateVehicle($request, $vehicle);

        return $vehicle->update($data);
    }

    protected function performDestroyVehicle(Vehicle $vehicle): ?bool
    {
        return $vehicle->delete();
    }
}
