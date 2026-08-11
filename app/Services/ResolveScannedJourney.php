<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Résolution d'un scan (QR ou numéro de ticket) vers le bon droit de voyage
 * (§6.1 du plan). Le scan reçoit le QR et le voyage actuellement contrôlé :
 *
 *     QR → billet → droits actifs → droit affecté au voyage contrôlé
 *
 * Réponses possibles :
 * - ticket valide pour l'aller ;
 * - ticket valide pour le retour ;
 * - retour valide mais non affecté à ce voyage ;
 * - retour non encore mobilisé ;
 * - droit déjà embarqué ;
 * - retour expiré, annulé ou remboursé ;
 * - billet introuvable.
 */
final class ResolveScannedJourney
{
    public const TICKET_NOT_FOUND = 'ticket_not_found';

    public const OUTBOUND_VALID = 'outbound_valid';

    public const RETURN_VALID = 'return_valid';

    public const RETURN_NOT_ON_THIS_TRIP = 'return_not_on_this_trip';

    public const RETURN_NOT_MOBILIZED = 'return_not_mobilized';

    public const ALREADY_BOARDED = 'already_boarded';

    public const RETURN_EXPIRED = 'return_expired';

    public const TICKET_CANCELLED = 'ticket_cancelled';

    public const TICKET_REFUNDED = 'ticket_refunded';

    public const RETURN_REFUNDED = 'return_refunded';

    public const JOURNEY_COMPLETED = 'journey_completed';

    /**
     * Résout une valeur de QR.
     *
     * @return array{code: string, ticket: Ticket|null, journey: TicketJourney|null, message: string}
     */
    public function resolve(string $qrValue, ?Trip $contextTrip = null): array
    {
        $ticket = $this->findTicket($qrValue);

        if (! $ticket) {
            return [
                'code' => self::TICKET_NOT_FOUND,
                'ticket' => null,
                'journey' => null,
                'message' => 'Billet introuvable.',
            ];
        }

        if ($ticket->status === 'cancelled') {
            return [
                'code' => self::TICKET_CANCELLED,
                'ticket' => $ticket,
                'journey' => null,
                'message' => 'Ce billet a été annulé.',
            ];
        }

        // Remboursement COMPLET : le billet est marqué refunded, aucun droit
        // ne peut plus être embarqué — refus immédiat, même avec un voyage
        // contrôlé (point E).
        if ($ticket->status === 'refunded') {
            return [
                'code' => self::TICKET_REFUNDED,
                'ticket' => $ticket,
                'journey' => null,
                'message' => 'Ce billet a été remboursé.',
            ];
        }

        // Remboursement PARTIEL (écriture compensatoire refund exécutée sur le
        // retour uniquement) : sans voyage contrôlé, le statut global prime.
        // Avec voyage contrôlé, resolveForTrip distingue l'aller (toujours
        // valide) du retour remboursé (return_refunded).
        if (! $contextTrip && $this->isRefunded($ticket)) {
            return [
                'code' => self::TICKET_REFUNDED,
                'ticket' => $ticket,
                'journey' => null,
                'message' => 'Ce billet a été remboursé.',
            ];
        }

        // Pas de voyage contrôlé : on retourne simplement le billet avec ses droits.
        if (! $contextTrip) {
            $journey = $ticket->outboundJourney;

            return [
                'code' => $journey ? self::OUTBOUND_VALID : self::TICKET_NOT_FOUND,
                'ticket' => $ticket,
                'journey' => $journey,
                'message' => $journey ? 'Ticket valide.' : 'Billet sans droit de voyage.',
            ];
        }

        return $this->resolveForTrip($ticket, $contextTrip);
    }

    /**
     * Recherche manuelle par numéro de ticket (même service de résolution).
     */
    public function resolveByTicketNumber(string $ticketNumber, ?Trip $contextTrip = null): array
    {
        $ticket = Ticket::where('ticket_number', $ticketNumber)->first();

        if (! $ticket) {
            return [
                'code' => self::TICKET_NOT_FOUND,
                'ticket' => null,
                'journey' => null,
                'message' => 'Billet introuvable.',
            ];
        }

        return $this->resolve($ticket->qrPayloadString(), $contextTrip);
    }

    /**
     * Sélectionne le bon droit selon le voyage contrôlé.
     *
     * @return array{code: string, ticket: Ticket, journey: TicketJourney|null, message: string}
     */
    private function resolveForTrip(Ticket $ticket, Trip $contextTrip): array
    {
        // AUCUN filtre de statut en amont : chaque état doit produire un
        // message métier précis (expiré, annulé, remboursé, terminé…).
        $journeys = $ticket->journeys()->get();

        $journeyStatus = function (TicketJourney $journey) use ($ticket): array {
            if ($journey->status === TicketJourney::STATUS_BOARDED) {
                return [
                    'code' => self::ALREADY_BOARDED,
                    'message' => 'Ce passager a déjà été embarqué sur ce voyage.',
                ];
            }

            if ($journey->status === TicketJourney::STATUS_COMPLETED) {
                return [
                    'code' => self::JOURNEY_COMPLETED,
                    'message' => 'Ce droit de voyage a déjà été consommé (voyage terminé).',
                ];
            }

            if ($journey->status === TicketJourney::STATUS_EXPIRED) {
                return [
                    'code' => self::RETURN_EXPIRED,
                    'message' => 'Ce retour est expiré.',
                ];
            }

            if ($journey->status === TicketJourney::STATUS_CANCELLED) {
                // Un retour annulé après remboursement partiel vs billet annulé.
                return $this->isRefunded($ticket)
                    ? [
                        'code' => self::RETURN_REFUNDED,
                        'message' => 'Ce retour a été remboursé.',
                    ]
                    : [
                        'code' => self::TICKET_CANCELLED,
                        'message' => 'Ce droit de voyage a été annulé.',
                    ];
            }

            return [
                'code' => null,
                'message' => null,
            ];
        };

        // 1. Droit affecté au voyage contrôlé (aller ou retour).
        $onTrip = $journeys->first(fn (TicketJourney $journey) => $journey->trip_id === $contextTrip->id);

        if ($onTrip) {
            $state = $journeyStatus($onTrip);

            if ($state['code'] !== null) {
                return [
                    'code' => $state['code'],
                    'ticket' => $ticket,
                    'journey' => $onTrip,
                    'message' => $state['message'],
                ];
            }

            return [
                'code' => $onTrip->isReturn() ? self::RETURN_VALID : self::OUTBOUND_VALID,
                'ticket' => $ticket,
                'journey' => $onTrip,
                'message' => $onTrip->isReturn()
                    ? 'Ticket valide pour le retour sur ce voyage.'
                    : 'Ticket valide pour l’aller sur ce voyage.',
            ];
        }

        // 2. Droit retour affecté à un autre voyage.
        $assignedElsewhere = $journeys->first(fn (TicketJourney $journey) => $journey->isReturn() && $journey->trip_id !== null);

        if ($assignedElsewhere) {
            $state = $journeyStatus($assignedElsewhere);

            if ($state['code'] !== null) {
                return [
                    'code' => $state['code'],
                    'ticket' => $ticket,
                    'journey' => $assignedElsewhere,
                    'message' => $state['message'],
                ];
            }

            return [
                'code' => self::RETURN_NOT_ON_THIS_TRIP,
                'ticket' => $ticket,
                'journey' => $assignedElsewhere,
                'message' => 'Retour valide mais affecté à un autre voyage.',
            ];
        }

        // 3. Droit retour non encore mobilisé (open / date_flexible /
        // fixed_schedule en attente), expiré, annulé ou remboursé.
        $returnJourney = $journeys->first(fn (TicketJourney $journey) => $journey->isReturn());

        if ($returnJourney) {
            $state = $journeyStatus($returnJourney);

            if ($state['code'] !== null) {
                return [
                    'code' => $state['code'],
                    'ticket' => $ticket,
                    'journey' => $returnJourney,
                    'message' => $state['message'],
                ];
            }

            if ($returnJourney->valid_until && $returnJourney->valid_until->lt(now())) {
                return [
                    'code' => self::RETURN_EXPIRED,
                    'ticket' => $ticket,
                    'journey' => $returnJourney,
                    'message' => 'Ce retour a dépassé sa date limite d’utilisation.',
                ];
            }

            return [
                'code' => self::RETURN_NOT_MOBILIZED,
                'ticket' => $ticket,
                'journey' => $returnJourney,
                'message' => 'Retour non encore mobilisé. Affectez-le à un voyage compatible.',
            ];
        }

        // 4. Aller affecté ailleurs ou aucun droit pour ce voyage.
        $outbound = $ticket->outboundJourney;

        if ($outbound && $outbound->trip_id !== null && $outbound->trip_id !== $contextTrip->id) {
            return [
                'code' => self::RETURN_NOT_ON_THIS_TRIP,
                'ticket' => $ticket,
                'journey' => $outbound,
                'message' => 'Ce billet est affecté à un autre voyage.',
            ];
        }

        return [
            'code' => self::TICKET_NOT_FOUND,
            'ticket' => $ticket,
            'journey' => null,
            'message' => 'Ce billet n’a pas de droit de voyage sur le voyage contrôlé.',
        ];
    }

    private function isRefunded(Ticket $ticket): bool
    {
        return \App\Models\TicketCompensation::where('ticket_id', $ticket->id)
            ->where('compensation_type', 'refund')
            ->where('status', 'executed')
            ->exists();
    }

    private function findTicket(string $qrValue): ?Ticket
    {
        return Ticket::with(['outboundJourney', 'returnJourney', 'fromStation', 'toStation'])->find(
            Ticket::resolveFromQrValue(trim($qrValue))?->id
        );
    }
}
