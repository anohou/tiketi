<?php

namespace App\Services;

use App\Domain\Ticketing\EvaluateTripSalesReadiness;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre le report explicite et audité de l'affectation du car réel,
 * puis ouvre les préventes sur capacité planifiée (sales_ready = true).
 *
 * Le report n'est possible que si :
 * - le voyage n'a pas encore de car réel ;
 * - la politique du voyage est allow_planned_capacity ;
 * - une capacité prévisionnelle positive est disponible ;
 * - l'auteur est un utilisateur habilité (admin, supervisor, seller).
 */
final class AuthorizePlannedCapacitySales
{
    public function __construct(private readonly EvaluateTripSalesReadiness $readiness) {}

    /**
     * @throws TicketingRuleViolation si le report n'est pas autorisé
     */
    public function authorize(Trip $trip, User $actor, string $reason): Trip
    {
        $this->assertCanAuthorize($trip);

        if ($trip->sales_ready) {
            return $trip;
        }

        DB::transaction(function () use ($trip, $actor, $reason): void {
            $trip->forceFill([
                'sales_ready' => true,
                'opened_at' => now(),
                'opened_by' => $actor->id,
                'vehicle_assignment_deferred_at' => now(),
                'vehicle_assignment_deferred_by' => $actor->id,
                'vehicle_assignment_deferred_reason' => $reason,
            ])->save();
        });

        return $trip->fresh();
    }

    /**
     * Évalue la possibilité du report SANS AUCUN EFFET DE BORD.
     * N'appelle jamais authorize() : aucune donnée n'est modifiée.
     *
     * @throws TicketingRuleViolation
     */
    private function assertCanAuthorize(Trip $trip): void
    {
        if ($trip->vehicle && ! $trip->vehicle->is_placeholder) {
            throw new TicketingRuleViolation(
                'real_vehicle_already_assigned',
                'Un car réel est déjà affecté à ce voyage. Le report de l’affectation n’est pas nécessaire.'
            );
        }

        if ($trip->vehiclePolicy() !== 'allow_planned_capacity') {
            throw new TicketingRuleViolation(
                'planned_capacity_policy_required',
                'La politique de ce voyage n’autorise pas la vente sur capacité planifiée. Affectez un car réel pour ouvrir les ventes.'
            );
        }

        if ($trip->planned_capacity_snapshot === null || $trip->planned_capacity_snapshot <= 0) {
            throw new TicketingRuleViolation(
                'planned_capacity_missing',
                'Ce voyage ne peut pas être vendu sur capacité planifiée : aucune capacité prévisionnelle configurée.'
            );
        }
    }

    /**
     * Vérifie (SANS AUCUN EFFET DE BORD) si un report serait accepté.
     */
    public function canAuthorize(Trip $trip, User $actor): bool
    {
        try {
            $this->assertCanAuthorize($trip);

            return true;
        } catch (TicketingRuleViolation) {
            return false;
        }
    }
}
