<?php

namespace App\Services;

use App\Models\TicketConnection;
use App\Models\TicketConnectionAssignment;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenConnectionService
{
    public function assign(
        TicketConnection $connection,
        Trip $trip,
        int $seatNumber,
        ?User $user = null,
        string $assignmentMode = 'manual',
        bool $allowReassignment = true,
        array $allocationMetadata = [],
    ): TicketConnection {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $observedOldTripId = TicketConnection::query()->whereKey($connection->id)->value('trip_id');

            try {
                return DB::transaction(function () use ($connection, $trip, $seatNumber, $user, $assignmentMode, $allowReassignment, $allocationMetadata, $observedOldTripId) {
                    $lockedTripIds = collect([$observedOldTripId, $trip->id])->filter()->unique()->sort()->values();
                    Trip::whereIn('id', $lockedTripIds)->orderBy('id')->lockForUpdate()->get();

                    $lockedConnection = TicketConnection::with('ticket')
                        ->whereKey($connection->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedConnection->trip_id !== $observedOldTripId) {
                        throw new ConcurrentConnectionMutation('La correspondance a changé pendant l’acquisition des verrous.');
                    }

                    if (! in_array($lockedConnection->status, ['pending', 'ready', 'assigned'], true)) {
                        throw ValidationException::withMessages(['connection' => 'Cette correspondance a déjà été traitée.']);
                    }
                    if ($lockedConnection->status === 'assigned' && ! $allowReassignment) {
                        throw ValidationException::withMessages(['connection' => 'Cette correspondance vient déjà d’être affectée.']);
                    }

                    $oldTripId = $lockedConnection->trip_id;
                    $oldSeatNumber = $lockedConnection->seat_number;
                    $lockedTrip = Trip::with(['route.routeStopOrders', 'vehicle.vehicleType'])->findOrFail($trip->id);

                    if (in_array($lockedTrip->status, ['departed', 'arrived', 'cancelled'], true)) {
                        throw ValidationException::withMessages(['trip_id' => 'Ce voyage ne peut plus recevoir de passager en correspondance.']);
                    }

                    $segments = app(TripSegmentService::class);
                    [$valid, $error, $indices, $start, $end] = $segments->validateSegment(
                        $lockedTrip,
                        $lockedConnection->transfer_station_id,
                        $lockedConnection->destination_station_id
                    );
                    if (! $valid) {
                        throw ValidationException::withMessages(['trip_id' => $error]);
                    }

                    $capacity = $lockedTrip->vehicle?->vehicleType?->seat_count ?? $lockedTrip->vehicle?->seat_count ?? 0;
                    if ($seatNumber < 1 || $seatNumber > $capacity) {
                        throw ValidationException::withMessages(['seat_number' => 'Cette place n’existe pas dans ce véhicule.']);
                    }

                    $occupancies = TripSeatOccupancy::with('ticket')
                        ->where('trip_id', $lockedTrip->id)
                        ->where('seat_number', $seatNumber)
                        ->when($oldTripId === $lockedTrip->id, fn ($query) => $query->where('ticket_id', '!=', $lockedConnection->ticket_id))
                        ->get();
                    if ($segments->overlappingSeatNumbers($occupancies, $indices, $start, $end)) {
                        throw ValidationException::withMessages(['seat_number' => 'Cette place est déjà occupée sur le segment de correspondance.']);
                    }

                    if ($oldTripId) {
                        TripSeatOccupancy::where('trip_id', $oldTripId)
                            ->where('ticket_id', $lockedConnection->ticket_id)
                            ->where('from_station_id', $lockedConnection->transfer_station_id)
                            ->where('to_station_id', $lockedConnection->destination_station_id)
                            ->delete();
                    }

                    TripSeatOccupancy::create([
                        'trip_id' => $lockedTrip->id,
                        'ticket_id' => $lockedConnection->ticket_id,
                        'seat_number' => $seatNumber,
                        'from_station_id' => $lockedConnection->transfer_station_id,
                        'to_station_id' => $lockedConnection->destination_station_id,
                    ]);

                    $settings = $lockedConnection->settings ?? [];
                    if ($allocationMetadata !== []) {
                        $settings['seat_allocation'] = $allocationMetadata;
                    }

                    $lockedConnection->update([
                        'trip_id' => $lockedTrip->id,
                        'seat_number' => $seatNumber,
                        'status' => 'assigned',
                        'assigned_at' => now(),
                        'assigned_by' => $user?->id,
                        'assignment_mode' => $assignmentMode,
                        'settings' => $settings ?: null,
                    ]);

                    TicketConnectionAssignment::create([
                        'ticket_connection_id' => $lockedConnection->id,
                        'from_trip_id' => $oldTripId,
                        'to_trip_id' => $lockedTrip->id,
                        'from_seat_number' => $oldSeatNumber,
                        'to_seat_number' => $seatNumber,
                        'action' => $oldTripId ? 'reassigned' : 'assigned',
                        'reason' => $oldTripId ? 'Réassignation opérationnelle de la correspondance.' : 'Première affectation de la correspondance.',
                        'performed_by' => $user?->id,
                    ]);

                    return app(ConnectionConflictService::class)->evaluate(
                        $lockedConnection->fresh(['ticket', 'transferStation', 'destinationStation', 'trip'])
                    );
                });
            } catch (ConcurrentConnectionMutation $exception) {
                if ($attempt === 3) {
                    throw ValidationException::withMessages([
                        'connection' => 'Cette correspondance est modifiée simultanément. Veuillez réessayer.',
                    ]);
                }
            }
        }

        throw ValidationException::withMessages(['connection' => 'Impossible d’affecter cette correspondance.']);
    }
}
