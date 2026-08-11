<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

final class VehicleOperationalStatusService
{
    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * Statuts d'un voyage synonyme d'un départ imminent / en cours.
     */
    private const UPCOMING_STATUSES = ['scheduled', 'boarding', 'delayed'];

    private const TRIP_COLUMNS = [
        'id', 'code', 'vehicle_id', 'status', 'departure_at', 'actual_departed_at',
        'origin_station_id', 'destination_station_id', 'route_id',
    ];

    private const TRIP_WITH = [
        'originStation:id,name',
        'destinationStation:id,name',
        'route:id,name,origin_station_id,destination_station_id',
        'route.originStation:id,name',
        'route.destinationStation:id,name',
    ];

    /**
     * Statut opérationnel d'un véhicule isolé.
     */
    public function forVehicle(Vehicle $vehicle): array
    {
        return $this->mapForVehicles(collect([$vehicle]))[$vehicle->id]
            ?? $this->available();
    }

    /**
     * Carte des statuts opérationnels indexée par identifiant de véhicule
     * (deux requêtes groupées, sans N+1).
     *
     * @param  iterable<Vehicle>|Collection  $vehicles
     * @return array<string, array{status: string, trip: array|null, inactive_reason: string|null}>
     */
    public function mapForVehicles($vehicles): array
    {
        $vehicles = collect($vehicles);
        $map = [];

        foreach ($vehicles as $vehicle) {
            if (! $vehicle->active) {
                $map[$vehicle->id] = [
                    'status' => self::STATUS_INACTIVE,
                    'trip' => null,
                    'inactive_reason' => $vehicle->inactive_reason,
                ];
            }
        }

        $activeIds = $vehicles->where('active', true)->pluck('id')->all();
        if (! $activeIds) {
            return $map;
        }

        // 1. Voyage en cours (parti, pas encore arrivé ni annulé)
        $departed = Trip::query()
            ->whereIn('vehicle_id', $activeIds)
            ->where('status', 'departed')
            ->with(self::TRIP_WITH)
            ->orderBy('departure_at')
            ->orderBy('created_at')
            ->get(self::TRIP_COLUMNS);

        foreach ($departed as $trip) {
            $map[$trip->vehicle_id] ??= [
                'status' => self::STATUS_IN_TRANSIT,
                'trip' => $this->summarize($trip),
                'inactive_reason' => null,
            ];
        }

        // 2. Prochain voyage programmé (pas encore parti)
        $upcoming = Trip::query()
            ->whereIn('vehicle_id', $activeIds)
            ->whereIn('status', self::UPCOMING_STATUSES)
            ->where('departure_at', '>=', now()->subHours(12))
            ->with(self::TRIP_WITH)
            ->orderBy('departure_at')
            ->get(self::TRIP_COLUMNS);

        foreach ($upcoming as $trip) {
            if (isset($map[$trip->vehicle_id])) {
                continue;
            }

            $map[$trip->vehicle_id] = [
                'status' => self::STATUS_SCHEDULED,
                'trip' => $this->summarize($trip),
                'inactive_reason' => null,
            ];
        }

        // 3. Véhicule disponible
        foreach ($vehicles->where('active', true) as $vehicle) {
            $map[$vehicle->id] ??= $this->available();
        }

        // 4. Calcul de la position / gare du pool actuel (dernière gare d'arrivée ou gare d'attache)
        $latestTrips = Trip::query()
            ->whereIn('vehicle_id', $vehicles->pluck('id')->all())
            ->whereIn('status', ['departed', 'arrived'])
            ->with(['destinationStation:id,name,city,code', 'route.destinationStation:id,name,city,code'])
            ->orderByDesc('departure_at')
            ->get();

        $latestTripByVehicle = $latestTrips->groupBy('vehicle_id')->map->first();

        foreach ($vehicles as $vehicle) {
            $lastTrip = $latestTripByVehicle[$vehicle->id] ?? null;
            $currentLocation = null;

            if ($lastTrip) {
                $dest = $lastTrip->destinationStation ?? $lastTrip->route?->destinationStation;
                if ($dest) {
                    $currentLocation = [
                        'id' => $dest->id,
                        'name' => $dest->name,
                        'city' => $dest->city,
                        'code' => $dest->code,
                        'source' => 'last_trip',
                    ];
                }
            }

            if (! $currentLocation && $vehicle->relationLoaded('currentStationAssignment') && $vehicle->currentStationAssignment?->station) {
                $st = $vehicle->currentStationAssignment->station;
                $currentLocation = [
                    'id' => $st->id,
                    'name' => $st->name,
                    'city' => $st->city,
                    'code' => $st->code,
                    'source' => 'home_station',
                ];
            }

            if (isset($map[$vehicle->id])) {
                $map[$vehicle->id]['current_location'] = $currentLocation;
            }
        }

        return $map;
    }

    /**
     * Compteurs par statut opérationnel pour une flotte donnée.
     *
     * @return array{in_transit: int, scheduled: int, available: int, inactive: int, total: int}
     */
    public function summaryForVehicles($vehicles): array
    {
        $totals = [
            self::STATUS_IN_TRANSIT => 0,
            self::STATUS_SCHEDULED => 0,
            self::STATUS_AVAILABLE => 0,
            self::STATUS_INACTIVE => 0,
        ];

        foreach ($this->mapForVehicles($vehicles) as $operational) {
            $totals[$operational['status']] = ($totals[$operational['status']] ?? 0) + 1;
        }

        $totals['total'] = array_sum($totals);

        return $totals;
    }

    /**
     * @return array{status: string, trip: null, inactive_reason: null}
     */
    private function available(): array
    {
        return [
            'status' => self::STATUS_AVAILABLE,
            'trip' => null,
            'inactive_reason' => null,
        ];
    }

    private function summarize(Trip $trip): array
    {
        $origin = $trip->originStation?->name
            ?? $trip->route?->originStation?->name;
        $destination = $trip->destinationStation?->name
            ?? $trip->route?->destinationStation?->name;

        return [
            'id' => $trip->id,
            'code' => $trip->code,
            'status' => $trip->status,
            'origin_station_id' => $trip->origin_station_id,
            'destination_station_id' => $trip->destination_station_id,
            'origin' => $origin,
            'destination' => $destination,
            'departure_at' => $trip->departure_at?->toIso8601String(),
            'departure_time' => $trip->departure_at?->format('H:i'),
            'departure_date' => $trip->departure_at?->toDateString(),
            'departed_at' => $trip->actual_departed_at?->format('H:i'),
        ];
    }
}