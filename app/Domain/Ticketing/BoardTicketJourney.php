<?php

namespace App\Domain\Ticketing;

use App\Models\CrewMember;
use App\Models\TicketJourney;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Embarquement d'un droit de voyage (aller OU retour), §6.2 du plan.
 *
 * - Verrouille le droit et son occupation de siège ;
 * - refuse un second embarquement (idempotent : même droit = refus) ;
 * - refuse l'embarquement sur un voyage différent de celui du droit ;
 * - l'aller et le retour peuvent être embarqués indépendamment avec le
 *   même QR, sans que l'un consomme l'autre ;
 * - refuse un voyage dont le car réel n'est pas opérationnel.
 */
final class BoardTicketJourney
{
    public function execute(CrewMember $actor, Trip $trip, TicketJourney $journey, ?CarbonInterface $occurredAt = null): TicketJourney
    {
        $occurredAt ??= now();

        return DB::transaction(function () use ($actor, $trip, $journey, $occurredAt) {
            // Verrouille le droit.
            $locked = TicketJourney::whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            // Point E : le BILLET PARENT doit exister et permettre l'embarquement.
            // Un billet remboursé (complet) ou annulé ne peut jamais embarquer,
            // même si le droit semble encore actif.
            $parentTicket = \App\Models\Ticket::whereKey($locked->ticket_id)->first();
            if (! $parentTicket) {
                throw new TicketingRuleViolation(
                    'ticket_not_found',
                    'Le billet parent de ce droit est introuvable.',
                    404
                );
            }

            if (in_array($parentTicket->status, ['cancelled', 'refunded'], true)) {
                throw new TicketingRuleViolation(
                    'ticket_not_boardable',
                    "Ce billet a été {$parentTicket->status} : il ne peut plus être embarqué.",
                    422
                );
            }

            if (! $locked->isAssignedToTrip()) {
                throw new TicketingRuleViolation(
                    'journey_not_assigned',
                    'Ce droit de voyage n’est affecté à aucun voyage. Affectez-le avant l’embarquement.',
                    422
                );
            }

            if ($locked->trip_id !== $trip->id) {
                throw new TicketingRuleViolation(
                    'wrong_trip',
                    'Ce droit est affecté à un autre voyage que celui contrôlé.',
                    409
                );
            }

            if (in_array($locked->status, [TicketJourney::STATUS_BOARDED, TicketJourney::STATUS_COMPLETED], true)) {
                throw new TicketingRuleViolation(
                    'already_boarded',
                    'Ce passager a déjà été embarqué sur ce voyage.',
                    409
                );
            }

            if (in_array($locked->status, [TicketJourney::STATUS_CANCELLED, TicketJourney::STATUS_EXPIRED], true)) {
                throw new TicketingRuleViolation(
                    'journey_not_boardable',
                    'Ce droit de voyage ne peut plus être embarqué ('.$locked->status.').',
                    422
                );
            }

            // Le voyage doit être opérationnel (car réel, pas de placeholder).
            if (! $trip->isOperationalReady() || $trip->hasPlaceholderVehicle()) {
                throw new TicketingRuleViolation(
                    'trip_not_operational',
                    'Ce voyage n’est pas prêt à embarquer (car réel non affecté).',
                    409
                );
            }

            // Verrouille l'occupation du siège si une place confirmée existe.
            if ($locked->seat_number !== null) {
                $occupancy = \App\Models\TripSeatOccupancy::where('trip_id', $trip->id)
                    ->where('seat_number', $locked->seat_number)
                    ->where('ticket_id', $locked->ticket_id)
                    ->lockForUpdate()
                    ->first();

                if (! $occupancy) {
                    throw new TicketingRuleViolation(
                        'seat_occupancy_missing',
                        'L’occupation du siège '.$locked->seat_number.' est introuvable pour ce droit.',
                        422
                    );
                }
            }

            $locked->update([
                'status' => TicketJourney::STATUS_BOARDED,
                'boarded_at' => $occurredAt,
                'boarded_by' => $actor->id,
            ]);

            // Synchronisation Okohi : embarquement (en file, non bloquante).
            try {
                app(\App\Services\OkohiTicketPublisher::class)->enqueue(
                    $locked->ticket,
                    \App\Models\OkohiTicketOutbox::OPERATION_UPDATE,
                );
            } catch (\Throwable $e) {
                // Ne jamais faire échouer l'embarquement à cause de la file.
            }

            return $locked->fresh();
        });
    }
}
