<?php

namespace App\Services;

use App\Models\Station;
use App\Models\StationVehicleAssignment;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Pool des véhicules (point G) : politique CENTRALISÉE utilisée par le
 * tableau des départs et la billetterie.
 *
 * Un véhicule n'est disponible pour un voyage que si :
 * - il appartient au tenant courant (toujours vrai dans un tenant isolé) ;
 * - il est actif ;
 * - il n'est pas un véhicule technique de planification (is_placeholder) ;
 * - il est affecté au pool de la gare d'origine (StationVehicleAssignment) ;
 * - l'affectation est valide à la date du voyage ;
 * - sa capacité est >= aux engagements actifs (vérifié par AssignRealVehicleToTrip).
 *
 * Pour un administrateur, la contrainte de pool de gare est levée (il peut
 * affecter n'importe quel véhicule actif du tenant), mais les règles métier
 * de capacité et d'exploitation s'appliquent toujours.
 */
final class VehiclePoolService
{
    public function listForStation(Station $station, string $serviceDate, ?bool $isAdmin = null): Collection
    {
        $isAdmin ??= auth()->user()?->isAdmin() ?? false;

        $query = Vehicle::with('vehicleType')
            ->where('active', true)
            ->where('is_placeholder', false)
            ->orderBy('identifier');

        if (! $isAdmin) {
            $poolVehicleIds = StationVehicleAssignment::query()
                ->where('station_id', $station->id)
                ->activeOn($serviceDate)
                ->pluck('vehicle_id');

            $lastTripVehicleIds = Trip::query()
                ->whereIn('status', ['departed', 'arrived'])
                ->where('destination_station_id', $station->id)
                ->pluck('vehicle_id');

            $allowedIds = $poolVehicleIds->merge($lastTripVehicleIds)->unique();

            $query->whereIn('id', $allowedIds);
        }

        return $query->get(['id', 'identifier', 'vehicle_type_id', 'seat_count', 'active', 'is_placeholder']);
    }

    public function listForTrip(Trip $trip, ?bool $isAdmin = null): Collection
    {
        $isAdmin ??= auth()->user()?->isAdmin() ?? false;

        $query = Vehicle::with('vehicleType')
            ->where('active', true)
            ->where('is_placeholder', false)
            ->orderBy('identifier');

        if (! $isAdmin) {
            $poolStationId = $trip->origin_station_id ?: $trip->route?->origin_station_id;

            if ($poolStationId) {
                $poolVehicleIds = StationVehicleAssignment::query()
                    ->where('station_id', $poolStationId)
                    ->activeOn($trip->departure_at)
                    ->pluck('vehicle_id');

                $lastTripVehicleIds = Trip::query()
                    ->whereIn('status', ['departed', 'arrived'])
                    ->where('destination_station_id', $poolStationId)
                    ->pluck('vehicle_id');

                $allowedIds = $poolVehicleIds->merge($lastTripVehicleIds)->unique();
                $query->whereIn('id', $allowedIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->get(['id', 'identifier', 'vehicle_type_id', 'seat_count', 'active', 'is_placeholder']);
    }

    /**
     * @throws \DomainException avec un message métier explicite
     */
    public function assertVehicleAllowedForTrip(Trip $trip, Vehicle $vehicle, ?bool $isAdmin = null): void
    {
        $isAdmin ??= auth()->user()?->isAdmin() ?? false;

        if (! $vehicle->active) {
            throw new \DomainException('Ce véhicule est inactif.');
        }

        if ($vehicle->is_placeholder) {
            throw new \DomainException('Un véhicule technique de planification ne peut pas être affecté comme car réel.');
        }

        if (! $isAdmin) {
            $poolStationId = $trip->origin_station_id ?: $trip->route?->origin_station_id;

            if (! $poolStationId) {
                throw new \DomainException('La gare de départ de ce voyage est introuvable.');
            }

            $inHomePool = StationVehicleAssignment::query()
                ->where('station_id', $poolStationId)
                ->where('vehicle_id', $vehicle->id)
                ->activeOn($trip->departure_at)
                ->exists();

            $inArrivalPool = Trip::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereIn('status', ['departed', 'arrived'])
                ->where('destination_station_id', $poolStationId)
                ->exists();

            if (! $inHomePool && ! $inArrivalPool) {
                throw new \DomainException('Ce véhicule ne fait pas partie du pool disponible (gare d\'attache ou position actuelle) pour cette gare à la date du voyage.');
            }
        }
    }
}
