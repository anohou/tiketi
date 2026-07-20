<?php

namespace App\Services;

use App\Models\Trip;
use DomainException;
use Illuminate\Support\Facades\DB;

final class TripStationProgression
{
    public function __construct(private readonly TripSegmentService $segments) {}

    /** @return array<int, string> */
    public function orderedStationIds(Trip $trip): array
    {
        $indices = $this->segments->stationIndices($trip);
        asort($indices);

        return array_keys($indices);
    }

    public function activeSalesStationId(Trip $trip): ?string
    {
        $stationIds = $this->orderedStationIds($trip);
        if ($stationIds === []) {
            return $trip->origin_station_id;
        }

        $configured = data_get($trip->settings, 'sales_progress.active_station_id');
        if ($configured && in_array($configured, $stationIds, true)) {
            return $configured;
        }

        $originIndex = array_search($trip->origin_station_id, $stationIds, true);
        $originIndex = $originIndex === false ? 0 : $originIndex;

        // Legacy departed trips did not persist their progression. Their first
        // downstream station is the safest operational fallback.
        if ($trip->status === 'departed') {
            return $stationIds[$originIndex + 1] ?? $stationIds[$originIndex] ?? null;
        }

        return $stationIds[$originIndex] ?? null;
    }

    public function nextStationId(Trip $trip): ?string
    {
        $stationIds = $this->orderedStationIds($trip);
        $activeStationId = $this->activeSalesStationId($trip);
        $activeIndex = array_search($activeStationId, $stationIds, true);

        if ($activeIndex === false) {
            return null;
        }

        return $stationIds[$activeIndex + 1] ?? null;
    }

    public function isActiveSalesStation(Trip $trip, string $stationId): bool
    {
        return $this->activeSalesStationId($trip) === $stationId;
    }

    /**
     * Persist the hand-off from the current station to the next one.
     */
    public function advance(Trip $trip, string $departedStationId): Trip
    {
        return DB::transaction(function () use ($trip, $departedStationId) {
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $activeStationId = $this->activeSalesStationId($locked);

            if ($activeStationId !== $departedStationId) {
                throw new DomainException('Le départ doit être enregistré par la gare qui a actuellement la main.');
            }

            $nextStationId = $this->nextStationId($locked);
            if (! $nextStationId) {
                throw new DomainException('Aucune gare suivante n’est disponible sur ce voyage.');
            }

            $settings = $locked->settings ?? [];
            $departedStations = data_get($settings, 'sales_progress.departed_station_ids', []);
            $departedStations[] = $departedStationId;

            data_set($settings, 'sales_progress.active_station_id', $nextStationId);
            data_set($settings, 'sales_progress.departed_station_ids', array_values(array_unique($departedStations)));
            data_set($settings, 'sales_progress.departures.'.$departedStationId, now()->toIso8601String());
            data_set($settings, 'sales_progress.last_departed_station_id', $departedStationId);
            data_set($settings, 'sales_progress.last_departed_at', now()->toIso8601String());

            $locked->settings = $settings;
            $locked->save();

            return $locked->fresh();
        });
    }
}
