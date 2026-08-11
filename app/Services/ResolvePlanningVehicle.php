<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleType;

/**
 * Fournit ou crée le véhicule technique de planification associé à un type
 * de véhicule prévisionnel. Un seul véhicule technique par tenant et par type
 * (ex: PLAN-BUS-50). Ces véhicules portent is_placeholder = true et active = false
 * afin de ne jamais apparaître dans la flotte exploitable.
 */
final class ResolvePlanningVehicle
{
    /**
     * Résout le véhicule technique pour un type donné.
     *
     * @throws \RuntimeException si le type n'existe pas ou est inactif
     */
    public function resolve(string $vehicleTypeId): Vehicle
    {
        $type = VehicleType::find($vehicleTypeId);

        if (! $type) {
            throw new \RuntimeException("Impossible de résoudre le véhicule technique : type de véhicule inconnu ({$vehicleTypeId}).");
        }

        $vehicle = Vehicle::where('vehicle_type_id', $vehicleTypeId)
            ->where('is_placeholder', true)
            ->first();

        if ($vehicle) {
            return $vehicle;
        }

        // PremierOrCreate sous verrou de base pour rester idempotent en concurrence.
        return Vehicle::firstOrCreate(
            [
                'vehicle_type_id' => $vehicleTypeId,
                'is_placeholder' => true,
            ],
            [
                'identifier' => $this->placeholderIdentifier($type),
                'maker' => 'Planification',
                'seat_count' => $type->seat_count,
                'active' => false,
                'inactive_reason' => 'Véhicule technique de planification — remplacé par un car réel avant exploitation.',
                'settings' => [
                    'planning_placeholder' => true,
                    'vehicle_type_id' => $type->id,
                ],
            ]
        );
    }

    /**
     * Identifiant stable du véhicule technique pour un type.
     */
    public function placeholderIdentifier(VehicleType $type): string
    {
        $capacity = $type->seat_count ?: 0;

        return sprintf('PLAN-BUS-%d', $capacity);
    }
}
