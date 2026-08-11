<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\OkohiTicketOutbox;
use App\Models\TicketJourney;
use App\Models\TicketJourneyAssignment;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rattachage et placement des retours.
 *
 * - Rattachement automatique : les retours fixed_schedule rejoignent le voyage
 *   matérialisé par le cron (programme + date), sans place définitive tant que
 *   le car réel n'est pas affecté.
 * - Affectation manuelle : un retour (date_flexible ou open) est placé sur un
 *   voyage compatible ; si le car réel est connu, une place peut être choisie.
 * - Réaffectation : retire l'ancien lien (et la place), consigne l'historique.
 *
 * Toutes les écritures sont atomiques et auditables (ticket_journey_assignments).
 */
final class ReturnJourneyAllocator
{
    public function __construct(
        private readonly TripCapacityService $capacity,
    ) {}

    /**
     * Rattache automatiquement les retours fixed_schedule d'un programme à un
     * voyage matérialisé. Idempotent et sans réservation définitive de place.
     *
     * @return int nombre de retours rattachés
     */
    public function attachScheduleReturns(Trip $trip): int
    {
        return DB::transaction(function () use ($trip) {
            $journeys = TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)
                ->where('selection_mode', TicketJourney::SELECTION_FIXED_SCHEDULE)
                ->where('departure_schedule_id', $trip->departure_schedule_id)
                ->whereDate('desired_travel_date', $trip->service_date)
                ->whereIn('status', [
                    TicketJourney::STATUS_AWAITING_TRIP,
                    TicketJourney::STATUS_READY,
                ])
                ->whereNull('trip_id')
                ->lockForUpdate()
                ->get();

            $attached = 0;
            foreach ($journeys as $journey) {
                $journey->update([
                    'trip_id' => $trip->id,
                    'vehicle_id' => $trip->vehicle_id,
                    'status' => TicketJourney::STATUS_ASSIGNED,
                    'assigned_at' => now(),
                ]);
                $attached++;
            }

            return $attached;
        });
    }

    /**
     * Affecte manuellement un retour sur un voyage compatible.
     *
     * @param  int|null  $seatNumber  null = réservation d'une unité de capacité sans place
     */
    public function assign(TicketJourney $journey, Trip $trip, ?int $seatNumber, User $actor): TicketJourney
    {
        return DB::transaction(function () use ($journey, $trip, $seatNumber, $actor) {
            $locked = TicketJourney::whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isReturn()) {
                throw new TicketingRuleViolation('not_return', 'Seuls les droits de retour peuvent être affectés ici.');
            }

            if (in_array($locked->status, [TicketJourney::STATUS_BOARDED, TicketJourney::STATUS_COMPLETED, TicketJourney::STATUS_CANCELLED, TicketJourney::STATUS_EXPIRED], true)) {
                throw new TicketingRuleViolation('journey_not_assignable', 'Ce retour ne peut plus être affecté (statut '.$locked->status.').');
            }

            // Compatibilité du trajet inverse.
            if ($locked->from_station_id !== $trip->origin_station_id || $locked->to_station_id !== $trip->destination_station_id) {
                throw new TicketingRuleViolation('incompatible_route', 'Ce voyage ne correspond pas au trajet du retour.');
            }

            // Le voyage doit être ouvert à la vente (car réel ou report
            // explicitement autorisé sur capacité planifiée).
            if (! $trip->isSalesReady()) {
                throw new TicketingRuleViolation('trip_not_sellable', 'Ce voyage n’est pas encore ouvert à la vente (car réel à affecter).');
            }

            // Sans car réel, on ne peut affecter le retour qu'EN QUANTITÉ
            // (capacité réservée, siège attribué à l'arrivée du car).
            if ($trip->hasPlaceholderVehicle() && $seatNumber !== null) {
                throw new TicketingRuleViolation(
                    'seat_requires_real_vehicle',
                    'Ce voyage n’a pas encore de car réel : affectez le retour sans numéro de siège, il sera placé après l’affectation du car.'
                );
            }

            // Capacité : une place précise consomme l'occupation, sinon une unité.
            if ($seatNumber !== null) {
                $occupied = TripSeatOccupancy::where('trip_id', $trip->id)
                    ->where('seat_number', $seatNumber)
                    ->exists();
                if ($occupied) {
                    throw new TicketingRuleViolation('seat_taken', "Le siège {$seatNumber} est déjà occupé sur ce voyage.");
                }
            }
            $this->capacity->reserveUnits($trip, 1, $locked->from_station_id, $locked->to_station_id);

            $oldTripId = $locked->trip_id;
            $oldSeat = $locked->seat_number;

            // Libère une éventuelle ancienne occupation, puis crée la nouvelle.
            if ($oldSeat !== null && $oldTripId !== null) {
                TripSeatOccupancy::where('trip_id', $oldTripId)
                    ->where('seat_number', $oldSeat)
                    ->where('ticket_id', $locked->ticket_id)
                    ->delete();
            }
            if ($seatNumber !== null) {
                TripSeatOccupancy::create([
                    'trip_id' => $trip->id,
                    'seat_number' => $seatNumber,
                    'ticket_id' => $locked->ticket_id,
                    'ticket_journey_id' => $locked->id,
                    'from_station_id' => $locked->from_station_id,
                    'to_station_id' => $locked->to_station_id,
                ]);
            }

            $locked->update([
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'seat_number' => $seatNumber,
                'seat_assignment_status' => $seatNumber !== null
                    ? TicketJourney::SEAT_CONFIRMED
                    : TicketJourney::SEAT_UNASSIGNED,
                'status' => TicketJourney::STATUS_ASSIGNED,
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
            ]);

            TicketJourneyAssignment::create([
                'ticket_journey_id' => $locked->id,
                'previous_trip_id' => $oldTripId,
                'new_trip_id' => $trip->id,
                'previous_seat_number' => $oldSeat,
                'new_seat_number' => $seatNumber,
                'reason' => 'manual_assignment',
                'mode' => TicketJourneyAssignment::MODE_MANUAL,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            // Synchronisation Okohi : affectation (en file, non bloquante).
            try {
                app(OkohiTicketPublisher::class)->enqueue(
                    $locked->ticket,
                    OkohiTicketOutbox::OPERATION_UPDATE,
                );
            } catch (\Throwable $e) {
                // Ne jamais faire échouer l'affectation à cause de la file.
            }

            return $locked->fresh();
        });
    }

    /**
     * Retire un retour d'un voyage (remise dans le pool), libère la place.
     */
    public function unassign(TicketJourney $journey, User $actor, ?string $reason = null): TicketJourney
    {
        return DB::transaction(function () use ($journey, $actor, $reason) {
            $locked = TicketJourney::whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isAssignedToTrip()) {
                throw new TicketingRuleViolation('not_assigned', 'Ce retour n’est affecté à aucun voyage.');
            }

            $oldTripId = $locked->trip_id;
            $oldSeat = $locked->seat_number;

            // Libère l'occupation physique éventuelle.
            if ($oldSeat !== null && $oldTripId !== null) {
                TripSeatOccupancy::where('trip_id', $oldTripId)
                    ->where('seat_number', $oldSeat)
                    ->where('ticket_id', $locked->ticket_id)
                    ->delete();
            }

            $newStatus = match ($locked->selection_mode) {
                TicketJourney::SELECTION_FIXED_SCHEDULE => TicketJourney::STATUS_AWAITING_TRIP,
                TicketJourney::SELECTION_DATE_FLEXIBLE => TicketJourney::STATUS_READY,
                default => TicketJourney::STATUS_PENDING,
            };

            $locked->update([
                'trip_id' => null,
                'vehicle_id' => null,
                'seat_number' => null,
                'seat_assignment_status' => TicketJourney::SEAT_UNASSIGNED,
                'status' => $newStatus,
                'assigned_at' => null,
                'assigned_by' => null,
            ]);

            TicketJourneyAssignment::create([
                'ticket_journey_id' => $locked->id,
                'previous_trip_id' => $oldTripId,
                'new_trip_id' => null,
                'previous_seat_number' => $oldSeat,
                'new_seat_number' => null,
                'reason' => $reason ?: 'manual_unassignment',
                'mode' => TicketJourneyAssignment::MODE_MANUAL,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            // Synchronisation Okohi : retrait (en file, non bloquante).
            try {
                app(OkohiTicketPublisher::class)->enqueue(
                    $locked->ticket,
                    OkohiTicketOutbox::OPERATION_UPDATE,
                );
            } catch (\Throwable $e) {
                // Ne jamais faire échouer le retrait à cause de la file.
            }

            return $locked->fresh();
        });
    }
}
