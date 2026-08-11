<?php

namespace App\Services;

use App\Models\OperationalSetting;
use App\Models\TicketJourney;

/**
 * Prix canonique d'un aller-retour. Le serveur recalcule toujours le prix à
 * partir des tarifs actifs (jamais le montant fourni par le client web) et
 * fige l'instantané au moment de la vente.
 *
 * - tarif aller : RouteFare direct ou bidirectionnel (tarif unitaire) ;
 * - tarif retour : RouteFare inverse (idem) ;
 * - remise globale : montant fixe en FCFA configuré dans les réglages
 *   d'exploitation (OperationalSetting::roundTripDiscountAmount()), soustrait
 *   du total normal quel que soit le trajet.
 *
 * Exemple d'acceptation du plan : 3 000 + 3 000 avec remise globale 500
 * ⇒ montant encaissé 5 500, remise historisée 500.
 */
final class RoundTripFareCalculator
{
    public function __construct(private readonly TripSegmentService $segments) {}

    /**
     * Calcule l'offre aller-retour pour un couple de gares.
     *
     * @return array{
     *     outbound_amount: int,
     *     return_amount: int,
     *     normal_total: int,
     *     round_trip_amount: int,
     *     round_trip_id: null,
     *     discount: int,
     *     amount_to_collect: int,
     *     allows_fixed_schedule: bool,
     *     allows_date_flexible: bool,
     *     allows_open_return: bool,
     *     default_validity_days: int,
     * }
     */
    public function calculate(string $fromStationId, string $toStationId, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $outbound = $this->segments->fareAmount($fromStationId, $toStationId);
        $return = $this->segments->fareAmount($toStationId, $fromStationId);

        $outboundAmount = $outbound ?? $return;
        $returnAmount = $return ?? $outbound;

        if ($outboundAmount === null || $returnAmount === null) {
            throw new \RuntimeException('Aucun tarif unitaire actif entre ces deux gares.');
        }

        $normalTotal = $outboundAmount + $returnAmount;
        $discount = OperationalSetting::current()->roundTripDiscountAmount();
        $roundTripAmount = max(0, $normalTotal - $discount);

        return [
            'outbound_amount' => $outboundAmount,
            'return_amount' => $returnAmount,
            'normal_total' => $normalTotal,
            'round_trip_amount' => $roundTripAmount,
            'round_trip_id' => null,
            'discount' => $discount,
            'amount_to_collect' => $roundTripAmount,
            'allows_fixed_schedule' => true,
            'allows_date_flexible' => true,
            'allows_open_return' => true,
            'default_validity_days' => 30,
        ];
    }

    /**
     * Valide le mode de sélection du retour contre l'offre.
     */
    public function validateSelectionMode(array $fare, string $selectionMode): bool
    {
        return match ($selectionMode) {
            TicketJourney::SELECTION_FIXED_SCHEDULE => $fare['allows_fixed_schedule'],
            TicketJourney::SELECTION_DATE_FLEXIBLE => $fare['allows_date_flexible'],
            TicketJourney::SELECTION_OPEN => $fare['allows_open_return'],
            default => false,
        };
    }
}
