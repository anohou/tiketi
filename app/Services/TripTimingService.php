<?php

namespace App\Services;

use App\Models\TicketConnection;
use App\Models\Trip;
use Carbon\CarbonInterface;

class TripTimingService
{
    public function plannedTimeAtStation(Trip $trip, string $stationId): ?CarbonInterface
    {
        return $this->timeAtStation($trip, $stationId, $trip->departure_at);
    }

    public function estimatedTimeAtStation(Trip $trip, string $stationId, ?CarbonInterface $departure = null): ?CarbonInterface
    {
        $departure ??= $trip->actual_departed_at;
        if (! $departure) {
            return null;
        }

        return $this->timeAtStation($trip, $stationId, $departure);
    }

    private function timeAtStation(Trip $trip, string $stationId, ?CarbonInterface $departure): ?CarbonInterface
    {
        $trip->loadMissing('route');
        $duration = $trip->route?->estimated_duration_minutes;
        if (! $duration || ! $departure) {
            return null;
        }

        $indices = app(TripSegmentService::class)->stationIndices($trip);
        if (! array_key_exists($stationId, $indices) || count($indices) < 2) {
            return null;
        }

        $originIndex = $indices[$trip->origin_station_id] ?? 0;
        $destinationIndex = $indices[$trip->destination_station_id] ?? (count($indices) - 1);
        $distance = max(1, $destinationIndex - $originIndex);
        $progress = max(0, min(1, ($indices[$stationId] - $originIndex) / $distance));
        $minutes = (int) round($duration * $progress);

        return $departure->copy()->addMinutes($minutes);
    }

    public function syncPlannedTimes(Trip $trip): Trip
    {
        $trip->loadMissing('route');
        $trip->updateQuietly([
            'planned_arrival_at' => $trip->route?->estimated_duration_minutes
                ? $trip->departure_at->copy()->addMinutes($trip->route->estimated_duration_minutes)
                : null,
        ]);

        TicketConnection::whereHas('ticket', fn ($query) => $query->where('trip_id', $trip->id))
            ->whereIn('status', ['pending', 'ready', 'assigned'])
            ->get()
            ->each(fn (TicketConnection $connection) => $connection->update([
                'planned_ready_at' => $this->plannedTimeAtStation($trip, $connection->transfer_station_id),
            ]));

        return $trip->fresh();
    }

    public function markDeparted(Trip $trip, ?CarbonInterface $departedAt = null): Trip
    {
        $departedAt ??= now();
        $trip->update([
            'status' => 'departed',
            'sales_control' => 'closed',
            'actual_departed_at' => $departedAt,
            'estimated_arrival_at' => $trip->route?->estimated_duration_minutes
                ? $departedAt->copy()->addMinutes($trip->route->estimated_duration_minutes)
                : null,
        ]);

        TicketConnection::whereHas('ticket', fn ($query) => $query->where('trip_id', $trip->id))
            ->whereIn('status', ['pending', 'ready', 'assigned'])
            ->get()
            ->each(function (TicketConnection $connection) use ($trip, $departedAt) {
                $connection->update([
                    'estimated_ready_at' => $this->estimatedTimeAtStation($trip, $connection->transfer_station_id, $departedAt),
                ]);
            });

        $conflicts = app(ConnectionConflictService::class);
        $conflicts->evaluateInboundTrip($trip);
        $conflicts->releaseUnboardedForDepartingTrip($trip->fresh());

        app(AutomaticConnectionAllocator::class)->allocateAllUpcoming();

        return $trip->fresh();
    }

    public function markArrived(Trip $trip, ?CarbonInterface $arrivedAt = null): Trip
    {
        $arrivedAt ??= now();
        $trip->update([
            'status' => 'arrived',
            'sales_control' => 'closed',
        ]);

        TicketConnection::where('trip_id', $trip->id)
            ->where('status', 'boarded')
            ->update([
                'status' => 'completed',
                'completed_at' => $arrivedAt,
            ]);

        return $trip->fresh();
    }

    public function markCancelled(Trip $trip): Trip
    {
        $trip->update([
            'status' => 'cancelled',
            'sales_control' => 'closed',
        ]);

        app(ConnectionConflictService::class)->releaseAllForCancelledTrip($trip->fresh());
        app(AutomaticConnectionAllocator::class)->allocateAllUpcoming();

        return $trip->fresh();
    }
}
