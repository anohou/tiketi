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
        $trip->loadMissing(['route.routeStopOrders.station']);

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

        $originId = $trip->origin_station_id ?? $route->origin_station_id;
        $destId = $trip->destination_station_id ?? $route->destination_station_id;

        $originIdx = array_search($originId, $orderedStationIds, true);
        $destIdx = array_search($destId, $orderedStationIds, true);

        if ($originIdx !== false && $destIdx !== false) {
            if ($originIdx <= $destIdx) {
                // Forward direction
                $slicedStationIds = array_slice($orderedStationIds, $originIdx, $destIdx - $originIdx + 1);
            } else {
                // Reversed direction
                $slicedStationIds = array_slice($orderedStationIds, $destIdx, $originIdx - $destIdx + 1);
                $slicedStationIds = array_reverse($slicedStationIds);
            }

            return array_flip($slicedStationIds);
        }

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
        $trip->loadMissing(['route.routeStopOrders.station']);

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

                if ($ticket) {
                    if ($ticket->status === 'cancelled') {
                        return false;
                    }
                } else {
                    // Si pas de ticket, on vérifie s'il y a un hold de récompense Okohi actif
                    $isHeld = $occupancy->okohi_reward_request_id
                        && $occupancy->expires_at
                        && $occupancy->expires_at->isFuture();
                    if (! $isHeld) {
                        return false;
                    }
                }

                $ticketStart = $stationIndices[$occupancy->from_station_id ?? $ticket?->from_station_id] ?? null;
                $ticketEnd = $stationIndices[$occupancy->to_station_id ?? $ticket?->to_station_id] ?? null;

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

    /**
     * Returns the seats that are truly available to sell from a station:
     * seats freed there by prior passengers, minus seats already resold from that station.
     */
    public function freedSeatsForStation(Trip $trip, string $stationId): array
    {
        $trip->loadMissing(['route.routeStopOrders.station', 'tripSeatOccupancies.ticket']);

        $effectiveSalesStations = $this->effectiveSalesStationIdsByStation($trip);
        $effectiveStationId = $effectiveSalesStations[$stationId] ?? null;
        if ($effectiveStationId !== $stationId) {
            return [];
        }

        $mappedStations = [];
        foreach ($effectiveSalesStations as $xId => $effId) {
            if ($effId === $stationId) {
                $mappedStations[] = $xId;
            }
        }

        $totalFreed = [];
        foreach ($mappedStations as $xId) {
            $freedAtX = $trip->tripSeatOccupancies
                ->filter(function (TripSeatOccupancy $occupancy) use ($xId) {
                    $ticket = $occupancy->ticket;

                    return $ticket && $ticket->status !== 'cancelled'
                        && ($occupancy->to_station_id ?? $ticket->to_station_id) === $xId;
                })
                ->pluck('seat_number')
                ->map(fn ($seatNumber) => (int) $seatNumber)
                ->unique()
                ->toArray();

            $resoldAtX = $trip->tripSeatOccupancies
                ->filter(function (TripSeatOccupancy $occupancy) use ($xId) {
                    $ticket = $occupancy->ticket;

                    return $ticket && $ticket->status !== 'cancelled'
                        && ($occupancy->from_station_id ?? $ticket->from_station_id) === $xId;
                })
                ->pluck('seat_number')
                ->map(fn ($seatNumber) => (int) $seatNumber)
                ->unique()
                ->toArray();

            $netFreedAtX = array_diff($freedAtX, $resoldAtX);
            $totalFreed = array_merge($totalFreed, $netFreedAtX);
        }

        return array_values(array_unique($totalFreed));
    }

    /**
     * Returns the seats that can be sold at a station.
     * Before departure, this is limited to seats freed at that station.
     * After departure, it also includes seats still empty on the vehicle.
     */
    public function sellableSeatsForStation(Trip $trip, string $stationId): array
    {
        $freedSeats = $this->freedSeatsForStation($trip, $stationId);

        if ($trip->status !== 'departed') {
            return $freedSeats;
        }

        $trip->loadMissing(['vehicle.vehicleType', 'tripSeatOccupancies.ticket']);
        $seatCount = $trip->vehicle?->vehicleType?->seat_count ?? $trip->vehicle?->seat_count ?? 0;

        $occupiedSeatNumbers = $trip->tripSeatOccupancies
            ->filter(fn (TripSeatOccupancy $occupancy) => $occupancy->ticket && $occupancy->ticket->status !== 'cancelled')
            ->pluck('seat_number')
            ->map(fn ($seatNumber) => (int) $seatNumber)
            ->unique()
            ->values()
            ->all();

        $emptySeats = [];
        for ($seatNumber = 1; $seatNumber <= $seatCount; $seatNumber++) {
            if (! in_array($seatNumber, $occupiedSeatNumbers, true)) {
                $emptySeats[] = $seatNumber;
            }
        }

        return array_values(array_unique(array_merge($emptySeats, $freedSeats)));
    }

    /**
     * Returns the nearest station on the route, at or before the given station,
     * that is allowed to sell tickets.
     */
    public function effectiveSalesStationIdForStation(Trip $trip, string $stationId): ?string
    {
        $effectiveSalesStations = $this->effectiveSalesStationIdsByStation($trip);

        return $effectiveSalesStations[$stationId] ?? null;
    }

    /**
     * Returns station_id => effective seller station_id, following route order.
     */
    public function effectiveSalesStationIdsByStation(Trip $trip): array
    {
        $trip->loadMissing(['route.routeStopOrders.station']);

        $stationIndices = $this->stationIndices($trip);
        $orderedStops = ($trip->route?->routeStopOrders ?? collect())
            ->sortBy(fn ($order) => $stationIndices[$order->station_id] ?? PHP_INT_MAX)
            ->values();
        $effectiveSalesStations = [];
        $lastSellableStationId = null;

        foreach ($orderedStops as $order) {
            if (! array_key_exists($order->station_id, $stationIndices)) {
                continue;
            }

            if (($order->station?->can_sell_tickets ?? true) === true) {
                $lastSellableStationId = $order->station_id;
            }

            $effectiveSalesStations[$order->station_id] = $lastSellableStationId;
        }

        return $effectiveSalesStations;
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
