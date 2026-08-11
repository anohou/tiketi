<?php

namespace App\Services;

use App\Models\OkohiTicketOutbox;
use App\Models\TicketJourney;
use App\Models\TicketJourneyAssignment;
use App\Models\TripSeatOccupancy;
use Illuminate\Support\Facades\DB;

/**
 * Expiration des retours ouverts ou non utilisés (§11, point K).
 *
 * Un retour arrive à expiration :
 * - passe en `expired` ;
 * - libère affectation et siège s'il en possède ;
 * - conserve l'historique (mode automatique, motif expiration) ;
 * - publie la mise à jour vers Okohi.
 *
 * Idempotent : un droit déjà `expired` ou consommé est ignoré.
 */
final class ExpireReturnJourneys
{
    /**
     * @return int nombre de retours expirés
     */
    public function expire(): int
    {
        return DB::transaction(function () {
            $now = now();

            $expirable = TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)
                ->whereIn('status', [
                    TicketJourney::STATUS_PENDING,
                    TicketJourney::STATUS_AWAITING_TRIP,
                    TicketJourney::STATUS_READY,
                    TicketJourney::STATUS_ASSIGNED,
                ])
                ->where('valid_until', '<', $now)
                ->lockForUpdate()
                ->get();

            $expired = 0;

            foreach ($expirable as $journey) {
                $oldTripId = $journey->trip_id;
                $oldSeat = $journey->seat_number;

                if ($oldSeat !== null && $oldTripId !== null) {
                    TripSeatOccupancy::where('trip_id', $oldTripId)
                        ->where('seat_number', $oldSeat)
                        ->where('ticket_id', $journey->ticket_id)
                        ->delete();
                }

                if ($oldTripId !== null) {
                    TicketJourneyAssignment::create([
                        'ticket_journey_id' => $journey->id,
                        'previous_trip_id' => $oldTripId,
                        'new_trip_id' => null,
                        'previous_seat_number' => $oldSeat,
                        'new_seat_number' => null,
                        'reason' => 'expired',
                        'mode' => TicketJourneyAssignment::MODE_AUTOMATIC,
                        'assigned_at' => now(),
                    ]);
                }

                $journey->update([
                    'trip_id' => null,
                    'vehicle_id' => null,
                    'seat_number' => null,
                    'seat_assignment_status' => TicketJourney::SEAT_UNASSIGNED,
                    'status' => TicketJourney::STATUS_EXPIRED,
                    'assigned_at' => null,
                    'assigned_by' => null,
                    'settings' => array_merge($journey->settings ?? [], [
                        'expired_at' => $now->toIso8601String(),
                    ]),
                ]);

                // Okohi : mise à jour (en file, non bloquante).
                try {
                    app(OkohiTicketPublisher::class)->enqueue(
                        $journey->ticket,
                        OkohiTicketOutbox::OPERATION_UPDATE,
                    );
                } catch (\Throwable $e) {
                    // La file ne fait jamais échouer l'expiration.
                }

                $expired++;
            }

            return $expired;
        });
    }
}
