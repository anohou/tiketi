<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\TripSeatOccupancy;
use Illuminate\Support\Facades\DB;

/**
 * Rapprochement quotidien entre billets, droits de voyage, occupations de
 * sièges et chiffre d'affaires. Détecte les doubles comptages et les oublis
 * pendant la période de double écriture (§12 Étape C).
 */
final class TicketReconciliationService
{
    /**
     * @return array{
     *     tickets_total: int,
     *     tickets_issued: int,
     *     journeys_total: int,
     *     journeys_outbound: int,
     *     journeys_return: int,
     *     tickets_without_outbound: int,
     *     occupancies: int,
     *     occupancies_without_journey_match: int,
     *     revenue_tickets: int,
     *     revenue_journeys_reference: int,
     *     anomalies: array<int, string>,
     * }
     */
    public function reconcile(?string $date = null): array
    {
        $date = $date ?: now()->toDateString();

        $ticketsTotal = Ticket::count();
        $ticketsIssued = Ticket::where('status', 'issued')->count();

        $journeysTotal = TicketJourney::count();
        $journeysOutbound = TicketJourney::where('direction', TicketJourney::DIRECTION_OUTBOUND)->count();
        $journeysReturn = TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)->count();

        $ticketsWithoutOutbound = Ticket::whereDoesntHave('outboundJourney')->count();

        $occupancies = TripSeatOccupancy::count();

        // Occupations liées à un billet qui n'a pas de droit correspondant.
        $occupanciesWithoutJourneyMatch = TripSeatOccupancy::query()
            ->whereNotNull('ticket_id')
            ->whereDoesntHave('ticket.outboundJourney')
            ->count();

        $revenueTickets = Ticket::where('status', 'issued')->sum(DB::raw('COALESCE(amount_collected, price)'));
        $revenueJourneysReference = TicketJourney::query()
            ->where('direction', TicketJourney::DIRECTION_OUTBOUND)
            ->whereHas('ticket', fn ($q) => $q->where('status', 'issued'))
            ->count();

        $anomalies = [];

        if ($ticketsTotal > 0 && $ticketsWithoutOutbound > 0) {
            $anomalies[] = "{$ticketsWithoutOutbound} billet(s) sans droit aller — lancer tickets:backfill-journeys.";
        }

        if ($occupanciesWithoutJourneyMatch > 0) {
            $anomalies[] = "{$occupanciesWithoutJourneyMatch} occupation(s) sans droit aller correspondant.";
        }

        if ($journeysReturn > $journeysOutbound) {
            $anomalies[] = 'Plus de droits retour que de droits aller.';
        }

        return [
            'tickets_total' => $ticketsTotal,
            'tickets_issued' => $ticketsIssued,
            'journeys_total' => $journeysTotal,
            'journeys_outbound' => $journeysOutbound,
            'journeys_return' => $journeysReturn,
            'tickets_without_outbound' => $ticketsWithoutOutbound,
            'occupancies' => $occupancies,
            'occupancies_without_journey_match' => $occupanciesWithoutJourneyMatch,
            'revenue_tickets' => (int) $revenueTickets,
            'revenue_journeys_reference' => $revenueJourneysReference,
            'anomalies' => $anomalies,
        ];
    }
}
