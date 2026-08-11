<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketJourney;
use Illuminate\Support\Carbon;

/**
 * Ventilation analytique du chiffre d'affaires aller/retour (§15).
 *
 * Un billet aller-retour est UNE vente : le CA n'est jamais doublé. Le montant
 * encaissé est réparti entre la part aller et la part retour selon les montants
 * normaux de l'offre, la remise étant affectée proportionnellement.
 *
 * Invariant : part_retour + part_aller = amount_collected.
 */
final class RoundTripRevenueService
{
    /**
     * Ventile un billet (round_trip ou one_way).
     *
     * @return array{
     *     ticket_id: string,
     *     journey_type: string,
     *     amount_collected: int,
     *     outbound_part: int,
     *     return_part: int,
     *     discount_amount: int,
     *     normal_total: int|null,
     * }
     */
    public function splitTicket(Ticket $ticket): array
    {
        $paid = (int) ($ticket->amount_collected ?? $ticket->price);
        $discount = (int) ($ticket->round_trip_discount_amount ?? 0);
        $normalTotal = $ticket->normal_total_amount !== null ? (int) $ticket->normal_total_amount : null;

        if ($ticket->journey_type !== Ticket::JOURNEY_TYPE_ROUND_TRIP || $normalTotal === null || $normalTotal <= 0) {
            // Aller simple : 100 % aller, 0 % retour.
            return [
                'ticket_id' => (string) $ticket->id,
                'journey_type' => $ticket->journey_type,
                'amount_collected' => $paid,
                'outbound_part' => $paid,
                'return_part' => 0,
                'discount_amount' => $discount,
                'normal_total' => $normalTotal,
            ];
        }

        // Répartition du forfait au prorata des montants normaux de l'offre.
        // normal_total = normal aller + normal retour (montants normaux).
        $normalReturn = $this->normalReturnAmount($ticket);
        $normalOutbound = $normalTotal - $normalReturn;

        if ($normalOutbound <= 0) {
            $outboundPart = (int) round($paid / 2);
            $returnPart = $paid - $outboundPart;
        } else {
            $outboundPart = (int) round($paid * $normalOutbound / $normalTotal);
            $returnPart = $paid - $outboundPart;
        }

        return [
            'ticket_id' => (string) $ticket->id,
            'journey_type' => $ticket->journey_type,
            'amount_collected' => $paid,
            'outbound_part' => $outboundPart,
            'return_part' => $returnPart,
            'discount_amount' => $discount,
            'normal_total' => $normalTotal,
        ];
    }

    /**
     * Agrège la ventilation sur un ensemble de billets.
     *
     * @param  iterable<Ticket>  $tickets
     * @return array{outbound: int, return: int, total: int, round_trip_count: int, one_way_count: int, discount: int}
     */
    public function aggregate(iterable $tickets): array
    {
        $totals = [
            'outbound' => 0,
            'return' => 0,
            'total' => 0,
            'round_trip_count' => 0,
            'one_way_count' => 0,
            'discount' => 0,
        ];

        foreach ($tickets as $ticket) {
            $split = $this->splitTicket($ticket);
            $totals['outbound'] += $split['outbound_part'];
            $totals['return'] += $split['return_part'];
            $totals['total'] += $split['amount_collected'];
            $totals['discount'] += $split['discount_amount'];
            $totals[$ticket->journey_type === Ticket::JOURNEY_TYPE_ROUND_TRIP ? 'round_trip_count' : 'one_way_count']++;
        }

        return $totals;
    }

    /**
     * Montant normal du retour à partir du droit retour (settings de l'offre)
     * ou par symétrie : normal_total - normal aller.
     */
    private function normalReturnAmount(Ticket $ticket): int
    {
        $return = $ticket->returnJourney;

        if ($return && $return->selection_mode === TicketJourney::SELECTION_OPEN) {
            // Retour ouvert : la part normale est la moitié du normal (par symétrie).
            return (int) floor(((int) ($ticket->normal_total_amount ?? 0)) / 2);
        }

        // Règle par défaut : moitié du normal total (l'offre étant symétrique).
        return (int) floor(((int) ($ticket->normal_total_amount ?? 0)) / 2);
    }

    /**
     * Ventilation d'une période (billets émis entre deux dates).
     *
     * @return array{period: array{from: string, to: string}, split: array}
     */
    public function forPeriod(?string $from = null, ?string $to = null): array
    {
        $query = Ticket::where('status', 'issued');

        if ($from) {
            $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to) {
            $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return [
            'period' => [
                'from' => $from ?? '—',
                'to' => $to ?? '—',
            ],
            'split' => $this->aggregate($query->get()),
        ];
    }
}
