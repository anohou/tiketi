<?php

namespace App\Services;

use App\Models\RouteFare;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use Illuminate\Support\Collection;

class TripSegmentService
{
    /**
     * Returns station_id => stop_index for the actual trip direction.
     */
    public function stationIndices(Trip $trip): array
    {
        $trip->loadMissing(['route.routeStopOrders']);

        $route = $trip->route;
        if (! $route) {
            return [];
        }

        $orderedStationIds = [];
        $addStation = function (?string $stationId) use (&$orderedStationIds): void {
            if ($stationId && ! in_array($stationId, $orderedStationIds, true)) {
                $orderedStationIds[] = $stationId;
            }
        };

        $addStation($route->origin_station_id);
        foreach (($route->routeStopOrders ?? collect())->sortBy('stop_index') as $order) {
            $addStation($order->station_id);
        }
        $addStation($route->destination_station_id);

        $indices = array_flip($orderedStationIds);

        if ($this->isReversed($trip)) {
            $max = count($indices) - 1;
            $indices = collect($indices)
                ->map(fn ($index) => $max - $index)
                ->toArray();
        }

        return $indices;
    }

    public function isReversed(Trip $trip): bool
    {
        $trip->loadMissing(['route.routeStopOrders']);

        $route = $trip->route;
        if (! $route || ! $trip->origin_station_id || ! $trip->destination_station_id) {
            return false;
        }

        // Get stop orders in forward direction
        $orderedStationIds = [];
        $addStation = function (?string $stationId) use (&$orderedStationIds): void {
            if ($stationId && ! in_array($stationId, $orderedStationIds, true)) {
                $orderedStationIds[] = $stationId;
            }
        };

        $addStation($route->origin_station_id);
        foreach (($route->routeStopOrders ?? collect())->sortBy('stop_index') as $order) {
            $addStation($order->station_id);
        }
        $addStation($route->destination_station_id);

        $indices = array_flip($orderedStationIds);

        $originIdx = $indices[$trip->origin_station_id] ?? null;
        $destIdx = $indices[$trip->destination_station_id] ?? null;

        if ($originIdx !== null && $destIdx !== null) {
            return $originIdx > $destIdx;
        }

        return $trip->origin_station_id !== $route->origin_station_id;
    }

    public function validateSegment(Trip $trip, string $fromStationId, string $toStationId): array
    {
        $indices = $this->stationIndices($trip);
        $start = $indices[$fromStationId] ?? null;
        $end = $indices[$toStationId] ?? null;

        if ($start === null || $end === null) {
            return [false, 'Segment d\'itinéraire invalide (gares non trouvées sur la route).', $indices, $start, $end];
        }

        if ($start === $end) {
            return [false, 'Gare de départ et d\'arrivée identiques.', $indices, $start, $end];
        }

        if ($start > $end) {
            return [false, 'Sens du trajet invalide (Départ après Arrivée).', $indices, $start, $end];
        }

        return [true, null, $indices, $start, $end];
    }

    public function overlappingSeatNumbers(Collection $occupancies, array $stationIndices, int $start, int $end): array
    {
        return $occupancies
            ->filter(function (TripSeatOccupancy $occupancy) use ($stationIndices, $start, $end) {
                $ticket = $occupancy->ticket;

                if (! $ticket || $ticket->status === 'cancelled') {
                    return false;
                }

                $ticketStart = $stationIndices[$ticket->from_station_id] ?? null;
                $ticketEnd = $stationIndices[$ticket->to_station_id] ?? null;

                if ($ticketStart === null || $ticketEnd === null) {
                    return true;
                }

                return $ticketStart < $end && $start < $ticketEnd;
            })
            ->pluck('seat_number')
            ->unique()
            ->values()
            ->all();
    }

    public function occupiedSeatsForSegment(Trip $trip, string $fromStationId, string $toStationId): array
    {
        [$valid, , $indices, $start, $end] = $this->validateSegment($trip, $fromStationId, $toStationId);

        if (! $valid) {
            return [];
        }

        $trip->loadMissing(['tripSeatOccupancies.ticket']);

        return $this->overlappingSeatNumbers($trip->tripSeatOccupancies, $indices, $start, $end);
    }

    public function fareAmount(string $fromStationId, string $toStationId): ?int
    {
        $direct = RouteFare::where('from_station_id', $fromStationId)
            ->where('to_station_id', $toStationId)
            ->where('active', true)
            ->first();

        if ($direct) {
            return $direct->amount;
        }

        $reverse = RouteFare::where('from_station_id', $toStationId)
            ->where('to_station_id', $fromStationId)
            ->where('is_bidirectional', true)
            ->where('active', true)
            ->first();

        return $reverse?->amount;
    }

    public function availableSeatCount(Trip $trip, ?string $fromStationId = null, ?string $toStationId = null): int
    {
        $total = $trip->vehicle?->vehicleType?->seat_count ?? $trip->vehicle?->seat_count ?? 0;

        if (! $fromStationId || ! $toStationId) {
            $occupied = Ticket::where('trip_id', $trip->id)
                ->where('status', '!=', 'cancelled')
                ->distinct('seat_number')
                ->count('seat_number');

            return max(0, $total - $occupied);
        }

        return max(0, $total - count($this->occupiedSeatsForSegment($trip, $fromStationId, $toStationId)));
    }
}
