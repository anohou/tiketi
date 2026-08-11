<?php

namespace App\Domain\Ticketing;

use App\Models\CrewMember;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripCapacityService;
use App\Services\TripStationProgression;

final class TripSalesPolicy
{
    public function __construct(private readonly EvaluateTripSalesReadiness $readiness) {}

    /** @param array<int, int> $requestedSeats */
    public function evaluate(User|CrewMember|null $actor, Trip $trip, string $fromStationId, string $toStationId, string $channel, array $requestedSeats): SalesDecision
    {
        if (! $actor) {
            return SalesDecision::deny('unauthenticated', 'Une authentification est requise pour vendre un ticket.');
        }
        if (in_array($trip->status, ['arrived', 'cancelled'], true)) {
            return SalesDecision::deny('trip_terminal', 'Aucune vente n’est permise sur un voyage terminé ou annulé.');
        }
        if ($trip->status === 'departed'
            && ! app(TripStationProgression::class)->isActiveSalesStation($trip, $fromStationId)) {
            return SalesDecision::deny('station_turn_pending', 'Cette gare n’a pas encore la main sur les ventes de ce voyage. Attendez le départ de la gare précédente.');
        }
        if ($fromStationId === $toStationId) {
            return SalesDecision::deny('invalid_segment', 'La gare de départ et la destination doivent être différentes.');
        }

        // Barrière véhicule : un voyage sans car réel n'est vendable que selon
        // la politique de capacité planifiée ET après le report explicite.
        if ($trip->isAwaitingRealVehicle()) {
            $ready = $this->readiness->evaluate($trip);

            if (! $ready->allowed) {
                return $ready;
            }

            // Politique allow_planned_capacity : vente en quantité, sans siège.
            if ($trip->allowsPlannedCapacitySales()) {
                if ($channel === 'crew') {
                    return SalesDecision::deny('planned_capacity_crew_forbidden', 'La vente à bord exige un car réel affecté.');
                }

                $capacityLeft = app(TripCapacityService::class)->remainingCapacity($trip);
                if ($capacityLeft <= 0) {
                    return SalesDecision::deny('planned_capacity_exhausted', 'La capacité planifiée de ce voyage est épuisée sans car réel affecté.');
                }

                if (count($requestedSeats) > $capacityLeft) {
                    return SalesDecision::deny('planned_capacity_exhausted', 'La capacité planifiée restante est insuffisante pour cette demande.');
                }

                return SalesDecision::allow();
            }
        }

        $seats = array_map('intval', $requestedSeats);
        if ($seats === [] || min($seats) < 1 || max($seats) > $trip->total_seats) {
            return SalesDecision::deny('invalid_seat', 'Une ou plusieurs places demandées n’existent pas dans ce véhicule.');
        }

        if ($channel === 'crew') {
            if (! $actor instanceof CrewMember) {
                return SalesDecision::deny('wrong_channel_actor', 'Ce canal de vente est réservé à l’équipage.');
            }
            if (! in_array($trip->status, ['boarding', 'departed'], true)) {
                return SalesDecision::deny('crew_sales_wrong_status', 'La vente à bord est autorisée uniquement pendant l’embarquement ou après le départ.');
            }
            $globalAllowed = TicketSetting::getSettings()->allowsCrewSales();
            $tripAllowed = (bool) data_get($trip->settings, 'allow_crew_sales', false);
            if (! $globalAllowed || ! $tripAllowed) {
                return SalesDecision::deny('crew_sales_disabled', 'Les ventes à bord sont désactivées par l’administrateur.');
            }

            return SalesDecision::allow();
        }

        if (! $actor instanceof User) {
            return SalesDecision::deny('wrong_channel_actor', 'Ce compte ne peut pas utiliser le canal de vente général.');
        }
        if (! in_array($actor->role, ['admin', 'supervisor', 'seller'], true)) {
            return SalesDecision::deny('role_forbidden', 'Votre rôle ne permet pas de vendre des tickets.');
        }
        if ($actor->role === 'seller') {
            if (! in_array($fromStationId, $actor->getActiveStationIds(), true)) {
                return SalesDecision::deny('station_forbidden', 'Vous n’êtes pas autorisé à vendre au départ de cette gare.');
            }

            $accessibleRouteIds = $actor->accessibleRoutesQuery()->pluck('id')->toArray();
            if (! in_array($trip->route_id, $accessibleRouteIds, true)) {
                return SalesDecision::deny('route_forbidden', 'Vous n’êtes pas autorisé à vendre des billets pour ce trajet.');
            }
        }

        return SalesDecision::allow();
    }
}
