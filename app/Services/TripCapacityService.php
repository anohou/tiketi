<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use Illuminate\Support\Facades\DB;

/**
 * Inventaire de capacité centralisé pour un voyage.
 *
 * La capacité engagée est le nombre d'engagements actifs sur un segment :
 *
 * - les droits de voyage (ticket_journeys) affectés au voyage — allers ET
 *   retours, qu'ils aient ou non un numéro de place (source canonique) ;
 * - les billets historiques sans droit de voyage pendant la transition
 *   (legacy) — sans double comptage avec les droits ;
 * - les holds Okohi encore valides.
 *
 * TripSeatOccupancy reste la représentation physique d'un siège confirmé.
 *
 * Invariant : engagements actifs sur un segment <= capacité du voyage
 * (capacité planifiée tant que le car réel n'est pas affecté, sinon
 * capacité du car réel).
 */
final class TripCapacityService
{
    public function __construct(private readonly TripSegmentService $segments) {}

    /**
     * Nombre d'engagements actifs sur le voyage, toutes places confondues.
     * Sans segment précisé : tous les engagements du voyage.
     * Avec segment : uniquement ceux dont le trajet chevauche le segment.
     */
    public function activeEngagements(Trip $trip, ?string $fromStationId = null, ?string $toStationId = null): int
    {
        $indices = $this->segments->stationIndices($trip);
        $start = ($fromStationId && $toStationId) ? ($indices[$fromStationId] ?? null) : null;
        $end = ($fromStationId && $toStationId) ? ($indices[$toStationId] ?? null) : null;
        $segmentFilter = $start !== null && $end !== null;

        $overlaps = function (int $journeyStart, int $journeyEnd) use ($start, $end): bool {
            return $journeyStart < $end && $start < $journeyEnd;
        };

        // 1. Droits de voyage affectés à CE voyage (source canonique).
        $journeys = TicketJourney::with('ticket')
            ->where('trip_id', $trip->id)
            ->whereIn('status', [
                TicketJourney::STATUS_ASSIGNED,
                TicketJourney::STATUS_BOARDED,
            ])
            ->get(['id', 'ticket_id', 'from_station_id', 'to_station_id', 'status']);

        $count = 0;
        $ticketIdsWithJourney = [];

        foreach ($journeys as $journey) {
            if ($segmentFilter) {
                $journeyStart = $indices[$journey->from_station_id] ?? null;
                $journeyEnd = $indices[$journey->to_station_id] ?? null;

                if ($journeyStart !== null && $journeyEnd !== null && ! $overlaps($journeyStart, $journeyEnd)) {
                    continue;
                }
            }

            $count++;
            $ticketIdsWithJourney[$journey->ticket_id] = true;
        }

        // 2. Billets historiques SANS droit affecté à ce voyage (transition).
        $legacy = Ticket::where('trip_id', $trip->id)
            ->where('status', 'issued')
            ->whereNotIn('id', array_keys($ticketIdsWithJourney))
            ->get(['id', 'from_station_id', 'to_station_id']);

        foreach ($legacy as $ticket) {
            if ($segmentFilter) {
                $ticketStart = $indices[$ticket->from_station_id] ?? null;
                $ticketEnd = $indices[$ticket->to_station_id] ?? null;

                if ($ticketStart === null || $ticketEnd === null) {
                    $count++;

                    continue;
                }

                if (! $overlaps($ticketStart, $ticketEnd)) {
                    continue;
                }
            }

            $count++;
        }

        // 3. Engagements sans ticket (ex: hold de récompense Okohi actif).
        $count += TripSeatOccupancy::where('trip_id', $trip->id)
            ->whereNull('ticket_id')
            ->whereNotNull('okohi_reward_request_id')
            ->where('expires_at', '>', now())
            ->count();

        return $count;
    }

    /**
     * Capacité restante sur le voyage (ou sur un segment donné).
     */
    public function remainingCapacity(Trip $trip, ?string $fromStationId = null, ?string $toStationId = null): int
    {
        $total = $trip->capacity();

        if ($total <= 0) {
            return 0;
        }

        return max(0, $total - $this->activeEngagements($trip, $fromStationId, $toStationId));
    }

    /**
     * Réserve atomiquement N unités de capacité, dans la limite disponible.
     *
     * @throws TicketingRuleViolation si la capacité est insuffisante
     */
    public function reserveUnits(Trip $trip, int $units, ?string $fromStationId = null, ?string $toStationId = null): void
    {
        if ($units <= 0) {
            return;
        }

        $available = DB::transaction(function () use ($trip, $fromStationId, $toStationId) {
            // Verrouille le voyage pour sérialiser les réservations concurrentes
            // (dernière place disponible).
            $locked = Trip::whereKey($trip->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return 0;
            }

            return $this->remainingCapacity($locked, $fromStationId, $toStationId);
        });

        if ($available < $units) {
            throw new TicketingRuleViolation(
                'capacity_exhausted',
                'La capacité restante de ce voyage est insuffisante pour cette demande.',
                422
            );
        }
    }
}
