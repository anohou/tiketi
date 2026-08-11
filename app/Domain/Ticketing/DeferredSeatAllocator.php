<?php

namespace App\Domain\Ticketing;

use App\Models\OkohiTicketOutbox;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\TicketJourneyAssignment;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Services\OkohiTicketPublisher;
use App\Services\OptimisationService;
use App\Services\TripSegmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attribue en lot les sièges des droits de voyage sans numéro de place
 * (ventes quantity_only sur capacité planifiée), une fois le car réel connu.
 *
 * Source canonique : `ticket_journeys` affectés au voyage (allers ET retours),
 * jamais uniquement `tickets.trip_id`. Les billets historiques sans droit sont
 * pris en compte via leur journey backfillé.
 *
 * Règles :
 * - l'allocation est déterministe (ordre de création puis groupes passagers) ;
 * - les groupes de passagers (même téléphone, sinon même nom réel) reçoivent
 *   des sièges contigus ; les passagers anonymes nommés « Passager » sans
 *   téléphone ne forment JAMAIS un groupe commun ;
 * - un siège n'est attribué que s'il n'entre pas en conflit avec un engagement
 *   dont le segment chevauche celui du droit (réutilisation entre segments
 *   non chevauchants) ;
 * - repli non contigu si aucun bloc contigu n'est disponible ;
 * - l'opération est atomique : aucun passager partiellement affecté en cas d'échec.
 *
 * @return array<int, TicketJourney> droits dont le siège vient d'être attribué
 */
final class DeferredSeatAllocator
{
    public const DEFAULT_PASSENGER_NAME = 'Passager';

    public function __construct(private readonly TripSegmentService $segments) {}

    /**
     * @throws TicketingRuleViolation si la capacité est insuffisante
     */
    public function allocate(Trip $trip): array
    {
        $unseated = $this->unseatedJourneys($trip);

        if ($unseated->isEmpty()) {
            return [];
        }

        $seatCount = $trip->vehicle?->seat_count
            ?? $trip->vehicle?->vehicleType?->seat_count
            ?? $trip->capacity();

        if ($seatCount <= 0) {
            throw new TicketingRuleViolation(
                'no_seat_map',
                'Le car affecté ne possède pas de plan de sièges exploitable.'
            );
        }

        $occupiedIntervals = $this->buildOccupiedIntervals($trip);

        // Groupes : même téléphone, sinon même nom RÉEL (jamais « Passager »
        // anonyme), sinon droit isolé.
        $groups = $this->groupJourneys($unseated);

        $vehicleType = $trip->vehicle?->vehicleType;
        $optService = app(OptimisationService::class);

        $allocated = [];

        DB::transaction(function () use ($groups, $seatCount, $occupiedIntervals, $trip, $vehicleType, $optService, &$allocated) {
            $lockedTrip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            foreach ($groups as $group) {
                $first = $group->first();
                $indices = $this->segments->stationIndices($lockedTrip);
                $start = $indices[$first->from_station_id] ?? null;
                $end = $indices[$first->to_station_id] ?? null;

                $block = $this->findContiguousBlock($occupiedIntervals, $seatCount, $group->count(), $start, $end);

                if ($block === null) {
                    throw new TicketingRuleViolation(
                        'seat_allocation_failed',
                        'Impossible d’attribuer des sièges à un groupe de passagers. Réaffectez manuellement les places.'
                    );
                }

                foreach ($group->values() as $index => $journey) {
                    $seatNumber = $block[$index];

                    $occupiedIntervals[$seatNumber][] = [$start ?? -1, $end ?? PHP_INT_MAX];

                    $boardingGroup = $vehicleType
                        ? $optService->computeBoardingGroup($vehicleType, $seatNumber)
                        : null;

                    $ticket = $journey->ticket;

                    // Droit de voyage : source canonique.
                    $journey->forceFill([
                        'seat_number' => $seatNumber,
                        'vehicle_id' => $lockedTrip->vehicle_id,
                        'seat_assignment_status' => TicketJourney::SEAT_CONFIRMED,
                        'status' => TicketJourney::STATUS_ASSIGNED,
                        'assigned_at' => now(),
                        'settings' => array_merge($journey->settings ?? [], [
                            'seat_assigned_at' => now()->toDateTimeString(),
                            'seat_assigned_by_allocator' => true,
                        ]),
                    ])->save();

                    // Billet : compatibilité (anciens champs) — jamais source
                    // de vérité, et UNIQUEMENT pour le droit OUTBOUND. Un retour
                    // ne doit jamais écraser le siège/véhicule historiques de
                    // l'aller (point D).
                    if ($ticket && $journey->direction === TicketJourney::DIRECTION_OUTBOUND) {
                        $ticket->forceFill([
                            'seat_number' => $seatNumber,
                            'vehicle_id' => $lockedTrip->vehicle_id,
                            'boarding_group' => $boardingGroup,
                        ])->save();
                    }

                    // Occupation physique (sans écraser les autres segments du siège).
                    $this->createOccupancy($lockedTrip, $journey, $seatNumber);

                    // Historique d'affectation.
                    TicketJourneyAssignment::create([
                        'ticket_journey_id' => $journey->id,
                        'previous_trip_id' => $journey->trip_id,
                        'new_trip_id' => $lockedTrip->id,
                        'previous_seat_number' => null,
                        'new_seat_number' => $seatNumber,
                        'reason' => 'deferred_allocation',
                        'mode' => TicketJourneyAssignment::MODE_AUTOMATIC,
                        'assigned_by' => null,
                        'assigned_at' => now(),
                    ]);

                    $allocated[] = $journey->fresh();
                }
            }

            // Version de l'allocation incrémentée une seule fois.
            $lockedTrip->forceFill([
                'seat_assignment_version' => $lockedTrip->seat_assignment_version + 1,
            ])->save();

            // Publication Okohi (en file, non bloquante) après commit.
            DB::afterCommit(function () use ($allocated) {
                foreach ($allocated as $journey) {
                    try {
                        app(OkohiTicketPublisher::class)->enqueue(
                            $journey->ticket,
                            OkohiTicketOutbox::OPERATION_UPDATE,
                        );
                    } catch (\Throwable $e) {
                        // La file ne fait jamais échouer l'allocation.
                    }
                }
            });
        });

        return $allocated;
    }

    /**
     * Droits de voyage affectés au voyage, sans siège confirmé.
     * Pendant la transition, les billets legacy sans journey reçoivent leur
     * droit outbound (backfill inline) afin d'être alloués comme les autres.
     */
    private function unseatedJourneys(Trip $trip): Collection
    {
        $journeys = TicketJourney::with('ticket')
            ->where('trip_id', $trip->id)
            ->whereIn('status', [
                TicketJourney::STATUS_ASSIGNED,
                TicketJourney::STATUS_AWAITING_TRIP,
            ])
            ->whereIn('seat_assignment_status', [
                TicketJourney::SEAT_UNASSIGNED,
            ])
            ->whereNull('seat_number')
            ->orderBy('created_at')
            ->get();

        // Billets legacy sans droit outbound (transition) : on crée le droit.
        $legacy = Ticket::where('trip_id', $trip->id)
            ->where('status', 'issued')
            ->whereNull('seat_number')
            ->whereDoesntHave('outboundJourney')
            ->orderBy('created_at')
            ->get();

        if ($legacy->isNotEmpty()) {
            $created = [];
            foreach ($legacy as $ticket) {
                $created[] = TicketJourney::create([
                    'ticket_id' => $ticket->id,
                    'direction' => TicketJourney::DIRECTION_OUTBOUND,
                    'from_station_id' => $ticket->from_station_id,
                    'to_station_id' => $ticket->to_station_id,
                    'selection_mode' => TicketJourney::SELECTION_FIXED_TRIP,
                    'trip_id' => $trip->id,
                    'vehicle_id' => $trip->vehicle_id,
                    'seat_number' => null,
                    'seat_assignment_status' => TicketJourney::SEAT_UNASSIGNED,
                    'status' => TicketJourney::STATUS_AWAITING_TRIP,
                    'valid_from' => now(),
                    'settings' => ['backfilled' => true, 'legacy_sale' => true],
                ]);
            }

            $journeys = $journeys->concat($created)->sortBy('created_at')->values();
        }

        return $journeys;
    }

    /**
     * Regroupe les droits par passager : téléphone, sinon nom réel,
     * sinon droit isolé. Les « Passager » anonymes sans téléphone restent
     * des droits isolés (pas un groupe commun).
     */
    private function groupJourneys(Collection $journeys): Collection
    {
        return $journeys->groupBy(function (TicketJourney $journey) {
            $ticket = $journey->ticket;

            if ($ticket?->passenger_phone) {
                return 'phone:'.$ticket->passenger_phone;
            }

            $name = trim((string) ($ticket?->passenger_name ?? ''));

            if ($name !== '' && $name !== self::DEFAULT_PASSENGER_NAME) {
                return 'name:'.$name;
            }

            return 'solo:'.$journey->id;
        });
    }

    /**
     * Intervalles occupés par siège, issus des TripSeatOccupancy existantes.
     *
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    private function buildOccupiedIntervals(Trip $trip): array
    {
        $indices = $this->segments->stationIndices($trip);
        $intervals = [];

        $occupancies = TripSeatOccupancy::with('ticket')
            ->where('trip_id', $trip->id)
            ->get();

        foreach ($occupancies as $occupancy) {
            $ticket = $occupancy->ticket;

            if ($ticket && $ticket->status !== 'issued') {
                continue;
            }

            if (! $ticket && ! ($occupancy->okohi_reward_request_id && $occupancy->expires_at?->isFuture())) {
                continue;
            }

            $fromId = $occupancy->from_station_id ?? $ticket?->from_station_id;
            $toId = $occupancy->to_station_id ?? $ticket?->to_station_id;

            $start = $fromId ? ($indices[$fromId] ?? null) : null;
            $end = $toId ? ($indices[$toId] ?? null) : null;

            $intervals[(int) $occupancy->seat_number][] = [
                $start ?? -1,
                $end ?? PHP_INT_MAX,
            ];
        }

        return $intervals;
    }

    private function createOccupancy(Trip $trip, TicketJourney $journey, int $seatNumber): void
    {
        // Réutilisation entre segments non chevauchants : on n'écrase JAMAIS
        // une occupation valide sur un segment différent.
        $existing = TripSeatOccupancy::where('trip_id', $trip->id)
            ->where('seat_number', $seatNumber)
            ->whereNull('ticket_id')
            ->whereNotNull('okohi_reward_request_id')
            ->where('expires_at', '>', now())
            ->exists();

        if ($existing) {
            throw new TicketingRuleViolation(
                'seat_hold_active',
                "Le siège {$seatNumber} est réservé par un hold Okohi actif."
            );
        }

        TripSeatOccupancy::create([
            'trip_id' => $trip->id,
            'seat_number' => $seatNumber,
            'ticket_id' => $journey->ticket_id,
            'ticket_journey_id' => $journey->id,
            'from_station_id' => $journey->from_station_id,
            'to_station_id' => $journey->to_station_id,
        ]);
    }

    /**
     * Cherche un bloc contigu de $size sièges libres sur le segment [start, end).
     *
     * @param  array<int, array<int, array{0: int, 1: int}>>  $occupiedIntervals
     * @return array<int, int>|null numéros de sièges, ou null si introuvable
     */
    private function findContiguousBlock(array $occupiedIntervals, int $seatCount, int $size, ?int $start, ?int $end): ?array
    {
        $block = [];

        for ($seat = 1; $seat <= $seatCount; $seat++) {
            $isFree = ! $this->seatConflicts($occupiedIntervals[$seat] ?? [], $start, $end);

            if ($isFree) {
                $block[] = $seat;

                if (count($block) === $size) {
                    return array_values($block);
                }
            } else {
                $block = [];
            }
        }

        // Aucun bloc contigu : repli non contigu (toujours essayé, taille 1
        // comprise, contrairement à l'ancien comportement).
        $loose = [];
        for ($seat = 1; $seat <= $seatCount; $seat++) {
            if (! $this->seatConflicts($occupiedIntervals[$seat] ?? [], $start, $end)) {
                $loose[] = $seat;
                if (count($loose) === $size) {
                    return $loose;
                }
            }
        }

        return null;
    }

    /**
     * Un siège est-il en conflit avec un intervalle chevauchant [start, end) ?
     *
     * @param  array<int, array{0: int, 1: int}>  $intervals
     */
    private function seatConflicts(array $intervals, ?int $start, ?int $end): bool
    {
        foreach ($intervals as [$occStart, $occEnd]) {
            if ($occStart < ($end ?? PHP_INT_MAX) && ($start ?? -1) < $occEnd) {
                return true;
            }
        }

        return false;
    }
}
