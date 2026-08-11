<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\OkohiTicketOutbox;
use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Models\TicketJourney;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remboursement partiel d'un aller-retour dont le retour n'a pas été utilisé
 * (§11 du plan).
 *
 * - Aller consommé, retour inutilisé : le billet reste partiellement utilisé ;
 *   seul le droit retour peut être remboursé, selon la politique de retour.
 * - Une annulation financière est une écriture compensatoire : les prix
 *   historiques (price, gross_amount, amount_collected) ne sont jamais écrasés.
 *   Le remboursement est historisé dans settings.refund + une TicketCompensation
 *   de type refund.
 * - Le droit retour passe à `cancelled` (plus aucun embarquement possible).
 * - Okohi reçoit la mise à jour de cycle de vie.
 */
final class TicketRefundService
{
    public function __construct(
        private readonly OkohiTicketPublisher $publisher,
    ) {}

    /**
     * Rembourse le retour d'un billet aller-retour.
     *
     * @return array{compensation: TicketCompensation, journey: TicketJourney|null, refunded_amount: int}
     */
    public function refundReturn(Ticket $ticket, User $actor, string $reason, ?int $amount = null): array
    {
        return DB::transaction(function () use ($ticket, $actor, $reason, $amount) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($ticket->journey_type !== Ticket::JOURNEY_TYPE_ROUND_TRIP) {
                throw new TicketingRuleViolation('not_round_trip', 'Seul un billet aller-retour peut faire l’objet d’un remboursement partiel du retour.', 422);
            }

            $return = $ticket->returnJourney;

            if (! $return) {
                throw new TicketingRuleViolation('no_return_journey', 'Ce billet n’a pas de droit retour.', 422);
            }

            if (in_array($return->status, [TicketJourney::STATUS_BOARDED, TicketJourney::STATUS_COMPLETED], true)) {
                throw new TicketingRuleViolation('return_already_used', 'Le retour a déjà été consommé : aucun remboursement possible.', 409);
            }

            if (in_array($return->status, [TicketJourney::STATUS_CANCELLED], true)) {
                throw new TicketingRuleViolation('already_refunded', 'Ce retour a déjà été annulé/remboursé.', 409);
            }

            // Montant maximal remboursable : la part du retour dans le prix payé.
            $maxRefundable = $this->maxRefundableAmount($ticket);
            $refundedAmount = $amount !== null ? (int) $amount : $maxRefundable;

            if ($refundedAmount <= 0) {
                throw new TicketingRuleViolation('refund_amount_invalid', 'Le montant à rembourser doit être positif.', 422);
            }
            if ($refundedAmount > $maxRefundable) {
                throw new TicketingRuleViolation(
                    'refund_exceeds_return_value',
                    "Le remboursement du retour ne peut pas dépasser {$maxRefundable} FCFA (valeur du retour).",
                    422,
                );
            }

            // Libère une éventuelle affectation du retour.
            if ($return->trip_id !== null) {
                app(ReturnJourneyAllocator::class)->unassign($return, $actor, 'refund_return');
                $return->refresh();
            }

            $compensation = TicketCompensation::create([
                'reference' => 'CMP-'.strtoupper(Str::random(10)),
                'ticket_id' => $ticket->id,
                'incident_type' => 'commercial',
                'compensation_type' => 'refund',
                'amount' => $refundedAmount,
                'status' => 'executed',
                'reason' => $reason,
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'executed_by' => $actor->id,
                'approved_at' => now(),
                'executed_at' => now(),
                'settings' => [
                    'scope' => 'return_only',
                    'return_journey_id' => $return->id,
                    'original_price' => (int) ($ticket->amount_collected ?? $ticket->price),
                ],
            ]);

            // Écriture compensatoire : on ne touche pas aux prix historiques.
            $settings = $ticket->settings ?? [];
            data_set($settings, 'refund.return_reference', $compensation->reference);
            data_set($settings, 'refund.return_amount', $refundedAmount);
            data_set($settings, 'refund.return_executed_at', now()->toIso8601String());

            $return->update([
                'status' => TicketJourney::STATUS_CANCELLED,
                'settings' => array_merge($return->settings ?? [], [
                    'refunded' => true,
                    'refund_reference' => $compensation->reference,
                    'refunded_at' => now()->toIso8601String(),
                ]),
            ]);

            $ticket->update(['settings' => $settings]);

            // Okohi : mise à jour du cycle de vie (en file, non bloquante).
            try {
                $this->publisher->enqueue($ticket, OkohiTicketOutbox::OPERATION_UPDATE);
            } catch (\Throwable $e) {
                // La file ne fait jamais échouer le remboursement.
            }

            return [
                'compensation' => $compensation,
                'journey' => $return->fresh(),
                'refunded_amount' => $refundedAmount,
            ];
        });
    }

    /**
     * Valeur remboursable du retour : montant normal du retour si connu,
     * sinon la moitié du prix payé (forfait), plafonné au prix payé.
     */
    public function maxRefundableAmount(Ticket $ticket): int
    {
        $normalReturn = $ticket->normal_total_amount !== null && $ticket->round_trip_discount_amount !== null
            ? (int) (($ticket->normal_total_amount - $ticket->round_trip_discount_amount) / 2)
            : 0;

        $paid = (int) ($ticket->amount_collected ?? $ticket->price);

        if ($normalReturn <= 0) {
            return (int) floor($paid / 2);
        }

        return min($normalReturn, $paid);
    }
}
