<?php

namespace App\Services;

use App\Models\OperationalSetting;
use App\Models\TicketConnection;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AutomaticConnectionAllocator
{
    public function allocateForTrip(Trip $trip, ?User $user = null, bool $force = false): Collection
    {
        $settings = OperationalSetting::current();
        $trip->loadMissing(['route.routeStopOrders', 'vehicle.vehicleType']);
        $enabled = $force || ($trip->automatic_connection_allocation
            ?? $trip->route?->automatic_connection_allocation
            ?? $settings->automatic_connection_allocation);
        if (! $enabled) {
            return collect();
        }

        $indices = app(TripSegmentService::class)->stationIndices($trip);
        $originIndex = $indices[$trip->origin_station_id] ?? null;
        if ($originIndex === null) {
            return collect();
        }

        $destinationIds = collect($indices)
            ->filter(fn ($index) => $index > $originIndex)
            ->keys();
        $latestArrival = $trip->departure_at->copy()->subMinutes(max(0, $settings->connection_transfer_buffer_minutes));

        $connections = TicketConnection::with('ticket')
            ->where('transfer_station_id', $trip->origin_station_id)
            ->whereIn('destination_station_id', $destinationIds)
            ->whereIn('status', ['pending', 'ready'])
            ->where(function ($query) use ($latestArrival) {
                $query->where('status', 'ready')
                    ->orWhere('estimated_ready_at', '<=', $latestArrival)
                    ->orWhere(function ($planned) use ($latestArrival) {
                        $planned->whereNull('estimated_ready_at')
                            ->where('planned_ready_at', '<=', $latestArrival);
                    });
            })
            ->get()
            ->sortBy(fn ($connection) => sprintf(
                '%d-%s',
                $connection->status === 'ready' ? 0 : 1,
                $connection->ticket?->created_at?->format('YmdHis.u') ?? '99999999999999'
            ));

        $assigned = collect();
        foreach ($connections as $connection) {
            $suggestion = $this->intelligentSeat($trip, $connection);
            if ($suggestion === null) {
                break;
            }

            try {
                $result = app(OpenConnectionService::class)->assign(
                    $connection,
                    $trip,
                    (int) $suggestion['seat_number'],
                    $user,
                    'automatic',
                    false,
                    [
                        'mode' => 'intelligent',
                        'score' => $suggestion['score'] ?? null,
                        'reason' => $suggestion['reason'] ?? null,
                    ],
                );
                $assigned->push($result->fresh());
            } catch (ValidationException) {
                continue;
            }
        }

        return $assigned;
    }

    public function allocateAllUpcoming(?User $user = null): Collection
    {
        return Trip::whereIn('status', ['scheduled', 'boarding'])
            ->where('departure_at', '>=', now())
            ->orderBy('departure_at')
            ->get()
            ->flatMap(fn (Trip $trip) => $this->allocateForTrip($trip, $user));
    }

    private function intelligentSeat(Trip $trip, TicketConnection $connection): ?array
    {
        $suggestions = app(OptimisationService::class)->getSuggestedSeats(
            $trip->id,
            $connection->destination_station_id,
            1,
            $connection->transfer_station_id
        );

        return $suggestions[0] ?? null;
    }
}
