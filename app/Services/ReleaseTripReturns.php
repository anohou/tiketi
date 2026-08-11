<?php

namespace App\Services;

use App\Models\OkohiTicketOutbox;
use App\Models\TicketJourney;
use App\Models\TicketJourneyAssignment;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

/**
 * Quand un voyage est annulé (§11), les retours affectés à ce voyage sont
 * remis dans le pool avec priorité : on libère les places, on consigne
 * l'historique (mode automatique, motif voyage annulé) et on resynchronise
 * Okohi. Les billets et droits restent intacts — aucun remboursement n'est
 * décidé ici, seule la politique de retour en décide.
 */
final class ReleaseTripReturns
{
    /**
     * @return int nombre de retours remis dans le pool
     */
    public function release(Trip $trip): int
    {
        return DB::transaction(function () use ($trip) {
            $journeys = TicketJourney::where('trip_id', $trip->id)
                ->where('direction', TicketJourney::DIRECTION_RETURN)
                ->whereIn('status', [
                    TicketJourney::STATUS_ASSIGNED,
                    TicketJourney::STATUS_BOARDED,
                ])
                ->lockForUpdate()
                ->get();

            $released = 0;

            foreach ($journeys as $journey) {
                $oldSeat = $journey->seat_number;

                if ($oldSeat !== null) {
                    \App\Models\TripSeatOccupancy::where('trip_id', $trip->id)
                        ->where('seat_number', $oldSeat)
                        ->where('ticket_id', $journey->ticket_id)
                        ->delete();
                }

                $newStatus = match ($journey->selection_mode) {
                    TicketJourney::SELECTION_FIXED_SCHEDULE => TicketJourney::STATUS_AWAITING_TRIP,
                    TicketJourney::SELECTION_DATE_FLEXIBLE => TicketJourney::STATUS_READY,
                    default => TicketJourney::STATUS_PENDING,
                };

                $journey->update([
                    'trip_id' => null,
                    'vehicle_id' => null,
                    'seat_number' => null,
                    'seat_assignment_status' => TicketJourney::SEAT_UNASSIGNED,
                    'status' => $newStatus,
                    'assigned_at' => null,
                    'assigned_by' => null,
                ]);

                TicketJourneyAssignment::create([
                    'ticket_journey_id' => $journey->id,
                    'previous_trip_id' => $trip->id,
                    'new_trip_id' => null,
                    'previous_seat_number' => $oldSeat,
                    'new_seat_number' => null,
                    'reason' => 'trip_cancelled',
                    'mode' => TicketJourneyAssignment::MODE_AUTOMATIC,
                    'assigned_at' => now(),
                ]);

                // Synchronisation Okohi (en file, non bloquante).
                try {
                    app(OkohiTicketPublisher::class)->enqueue(
                        $journey->ticket,
                        OkohiTicketOutbox::OPERATION_UPDATE,
                    );
                } catch (\Throwable $e) {
                    // Ne jamais faire échouer l'annulation du voyage à cause de la file.
                }

                $released++;
            }

            return $released;
        });
    }
}
