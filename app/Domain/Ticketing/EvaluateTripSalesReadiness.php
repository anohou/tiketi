<?php

namespace App\Domain\Ticketing;

use App\Models\DepartureSchedule;
use App\Models\OperationalSetting;
use App\Models\Trip;

/**
 * Évalue si un voyage peut ouvrir ses ventes, selon la politique de la
 * compagnie et du programme, et l'état du véhicule.
 *
 * Règles :
 * - un car réel affecté => ventes ouvertes (sales_ready = true) ;
 * - véhicule technique + politique require_real_vehicle => fermé ;
 * - véhicule technique + politique allow_planned_capacity => fermé tant que
 *   le report explicite du car n'est pas enregistré ;
 * - le cron n'ouvre jamais automatiquement les ventes.
 */
final class EvaluateTripSalesReadiness
{
    public function __construct(private readonly OperationalSetting $settings) {}

    /**
     * Décision de vente pour un voyage.
     */
    public function evaluate(Trip $trip): SalesDecision
    {
        if (in_array($trip->status, ['arrived', 'cancelled'], true)) {
            return SalesDecision::deny('trip_terminal', 'Aucune vente n’est permise sur un voyage terminé ou annulé.');
        }

        // Un car réel est affecté : les ventes sont ouvertes.
        if ($trip->vehicle && ! $trip->vehicle->is_placeholder) {
            return SalesDecision::allow();
        }

        // Aucun car réel : la politique détermine la suite.
        $policy = $trip->vehiclePolicy();

        if ($policy === DepartureSchedule::POLICY_REQUIRE_REAL_VEHICLE) {
            return SalesDecision::deny(
                'real_vehicle_required',
                'Ce voyage n’a pas encore de car réel affecté. Affectez un car pour ouvrir les ventes.'
            );
        }

        if ($policy === DepartureSchedule::POLICY_ALLOW_PLANNED_CAPACITY) {
            if (! $trip->sales_ready) {
                return SalesDecision::deny(
                    'planned_capacity_not_authorized',
                    'Ce voyage est en vente sur capacité planifiée, mais le report de l’affectation du car n’a pas encore été confirmé.'
                );
            }

            if ($trip->planned_capacity_snapshot === null || $trip->planned_capacity_snapshot <= 0) {
                return SalesDecision::deny(
                    'planned_capacity_missing',
                    'Ce voyage ne peut pas être vendu sur capacité planifiée : aucune capacité prévisionnelle configurée.'
                );
            }

            return SalesDecision::allow();
        }

        return SalesDecision::deny('unknown_policy', 'Politique d’affectation de véhicule inconnue pour ce voyage.');
    }

    /**
     * Politique par défaut de la compagnie (paramètre compagnie).
     */
    public function companyDefaultPolicy(): string
    {
        return (string) data_get(
            $this->settings->settings,
            'default_vehicle_assignment_policy',
            DepartureSchedule::POLICY_REQUIRE_REAL_VEHICLE
        );
    }
}
