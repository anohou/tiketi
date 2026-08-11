<?php

namespace App\Http\Controllers\Traits;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ManagesVehicles
{
    protected function validateVehicle(Request $request, ?Vehicle $vehicle = null): array
    {
        $data = $request->validate([
            'identifier' => 'required|string|max:255|unique:vehicles,identifier'.($vehicle ? ','.$vehicle->id : ''),
            'maker' => 'nullable|string|max:255',
            'vehicle_type_id' => 'required|uuid|exists:vehicle_types,id',
            'seat_count' => 'required|integer|min:1',
            'active' => 'boolean',
            'inactive_reason' => 'nullable|string|required_if:active,false',
            'insurance_expiry_date' => 'nullable|date',
        ]);

        // Un véhicule technique de planification ne peut jamais être rendu
        // actif manuellement : il resterait invisible dans la flotte exploitable.
        if ($vehicle?->isPlanningPlaceholder() && ($data['active'] ?? false)) {
            throw ValidationException::withMessages([
                'active' => 'Un véhicule technique de planification ne peut pas être activé comme car réel.',
            ]);
        }

        return $data;
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
        // Un véhicule technique référencé par un voyage matérialisé ne peut
        // pas être supprimé : le voyage conserverait une référence invalide.
        if ($vehicle->isPlanningPlaceholder()
            && $vehicle->trips()->whereIn('status', ['scheduled', 'boarding', 'delayed'])->exists()) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce véhicule technique est référencé par des voyages en attente d’un car réel. Supprimez ou remplacez ces voyages avant de le supprimer.',
            ]);
        }

        return $vehicle->delete();
    }
}
