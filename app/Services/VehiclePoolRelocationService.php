<?php

namespace App\Services;

use App\Models\StationVehicleAssignment;
use App\Models\Trip;
use App\Models\Vehicle;

final class VehiclePoolRelocationService
{
    /**
     * Réaffecter le véhicule au pool de la gare de destination dès le départ du voyage.
     */
    public function relocateToDestinationStation(Trip $trip): void
    {
        $trip->loadMissing(['vehicle', 'route']);
        $vehicle = $trip->vehicle;

        // Ne rien faire si pas de véhicule réel, si véhicule inactif ou véhicule technique
        if (! $vehicle || $vehicle->is_placeholder || ! $vehicle->active) {
            return;
        }

        $destinationStationId = $trip->destination_station_id ?: $trip->route?->destination_station_id;
        if (! $destinationStationId) {
            return;
        }

        $departureDate = $trip->departure_at ? $trip->departure_at->toDateString() : now()->toDateString();

        // 1. Désactiver / clôturer les affectations actives précédentes de ce véhicule
        StationVehicleAssignment::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('active', true)
            ->get()
            ->each(function (StationVehicleAssignment $assignment) use ($departureDate) {
                $assignment->update([
                    'active' => false,
                    'valid_until' => $assignment->valid_from && $assignment->valid_from->isAfter($departureDate)
                        ? $assignment->valid_from
                        : $departureDate,
                ]);
            });

        // 2. Créer la nouvelle affectation au pool de la gare de destination
        StationVehicleAssignment::create([
            'station_id' => $destinationStationId,
            'vehicle_id' => $vehicle->id,
            'valid_from' => $departureDate,
            'valid_until' => null,
            'active' => true,
            'notes' => "Réaffectation automatique suite au départ du voyage #{$trip->id}",
        ]);
    }

    /**
     * Remettre le véhicule dans le pool général en désactivant toutes ses affectations de gare (ex: panne/inactivité).
     */
    public function returnToGeneralPool(Vehicle $vehicle): void
    {
        StationVehicleAssignment::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('active', true)
            ->get()
            ->each(function (StationVehicleAssignment $assignment) {
                $assignment->update([
                    'active' => false,
                    'valid_until' => now()->toDateString(),
                ]);
            });
    }
}
