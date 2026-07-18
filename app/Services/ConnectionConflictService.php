<?php

namespace App\Services;

use App\Models\OperationalSetting;
use App\Models\TicketConnection;
use App\Models\TicketConnectionAssignment;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use Illuminate\Support\Facades\DB;

class ConnectionConflictService
{
    public function evaluateInboundTrip(Trip $inboundTrip): void
    {
        TicketConnection::with('trip')
            ->whereHas('ticket', fn ($query) => $query->where('trip_id', $inboundTrip->id))
            ->where('status', 'assigned')
            ->get()
            ->each(fn (TicketConnection $connection) => $this->evaluate($connection));
    }

    public function evaluate(TicketConnection $connection): TicketConnection
    {
        $connection->loadMissing('trip');
        if ($connection->status !== 'assigned' || ! $connection->trip_id || ! $connection->estimated_ready_at) {
            return $connection;
        }

        $buffer = max(0, OperationalSetting::current()->connection_transfer_buffer_minutes);
        $limit = $connection->trip->departure_at->copy()->subMinutes($buffer);
        $hasConflict = $connection->estimated_ready_at->greaterThan($limit);
        $settings = $connection->settings ?? [];

        if ($hasConflict) {
            $settings['has_conflict'] = true;
            $settings['conflict_reason'] = sprintf(
                'Arrivée estimée à %s après la limite de correspondance de %s.',
                $connection->estimated_ready_at->format('d/m/Y H:i'),
                $limit->format('d/m/Y H:i')
            );
            $settings['conflict_detected_at'] = now()->toIso8601String();
        } else {
            unset($settings['has_conflict'], $settings['conflict_reason'], $settings['conflict_detected_at']);
        }

        $connection->update(['settings' => $settings ?: null]);

        return $connection->fresh('trip');
    }

    public function releaseUnboardedForDepartingTrip(Trip $trip): int
    {
        $connectionIds = TicketConnection::where('trip_id', $trip->id)
            ->where('status', 'assigned')
            ->pluck('id');

        $released = 0;
        foreach ($connectionIds as $connectionId) {
            DB::transaction(function () use ($connectionId, $trip, &$released) {
                $connection = TicketConnection::whereKey($connectionId)->lockForUpdate()->first();
                if (! $connection || $connection->status !== 'assigned' || $connection->trip_id !== $trip->id) {
                    return;
                }

                TripSeatOccupancy::where('trip_id', $trip->id)
                    ->where('ticket_id', $connection->ticket_id)
                    ->where('from_station_id', $connection->transfer_station_id)
                    ->where('to_station_id', $connection->destination_station_id)
                    ->delete();

                $settings = $connection->settings ?? [];
                unset($settings['has_conflict'], $settings['conflict_reason'], $settings['conflict_detected_at']);
                $settings['last_missed_assignment'] = [
                    'trip_id' => $trip->id,
                    'seat_number' => $connection->seat_number,
                    'departed_at' => ($trip->actual_departed_at ?? now())->toIso8601String(),
                    'reason' => 'Le voyage de correspondance est parti avant l’embarquement du passager.',
                ];

                TicketConnectionAssignment::create([
                    'ticket_connection_id' => $connection->id,
                    'from_trip_id' => $trip->id,
                    'from_seat_number' => $connection->seat_number,
                    'action' => 'released_after_departure',
                    'reason' => 'Voyage parti sans embarquement du passager en correspondance.',
                    'metadata' => ['departed_at' => $trip->actual_departed_at?->toIso8601String()],
                ]);

                $connection->update([
                    'trip_id' => null,
                    'seat_number' => null,
                    'status' => $connection->ready_at ? 'ready' : 'pending',
                    'assigned_at' => null,
                    'assigned_by' => null,
                    'assignment_mode' => null,
                    'settings' => $settings,
                ]);
                $released++;
            });
        }

        return $released;
    }

    public function releaseAllForCancelledTrip(Trip $trip): int
    {
        $connectionIds = TicketConnection::where('trip_id', $trip->id)
            ->whereIn('status', ['assigned', 'boarded'])
            ->pluck('id');

        $released = 0;
        foreach ($connectionIds as $connectionId) {
            DB::transaction(function () use ($connectionId, $trip, &$released) {
                $connection = TicketConnection::whereKey($connectionId)->lockForUpdate()->first();
                if (! $connection || ! in_array($connection->status, ['assigned', 'boarded'], true) || $connection->trip_id !== $trip->id) {
                    return;
                }

                TripSeatOccupancy::where('trip_id', $trip->id)
                    ->where('ticket_id', $connection->ticket_id)
                    ->where('from_station_id', $connection->transfer_station_id)
                    ->where('to_station_id', $connection->destination_station_id)
                    ->delete();

                $settings = $connection->settings ?? [];
                unset($settings['has_conflict'], $settings['conflict_reason'], $settings['conflict_detected_at']);
                $settings['last_cancelled_assignment'] = [
                    'trip_id' => $trip->id,
                    'seat_number' => $connection->seat_number,
                    'cancelled_at' => now()->toIso8601String(),
                    'reason' => 'Le voyage de correspondance a été annulé.',
                ];

                TicketConnectionAssignment::create([
                    'ticket_connection_id' => $connection->id,
                    'from_trip_id' => $trip->id,
                    'from_seat_number' => $connection->seat_number,
                    'action' => 'released_after_cancellation',
                    'reason' => 'Annulation du voyage de correspondance.',
                    'metadata' => ['cancelled_at' => now()->toIso8601String()],
                ]);

                $connection->update([
                    'trip_id' => null,
                    'seat_number' => null,
                    'status' => $connection->ready_at ? 'ready' : 'pending',
                    'assigned_at' => null,
                    'assigned_by' => null,
                    'assignment_mode' => null,
                    'boarded_at' => null,
                    'boarded_by' => null,
                    'settings' => $settings,
                ]);
                $released++;
            });
        }

        return $released;
    }
}
