<?php

namespace App\Services;

use App\Domain\Ticketing\DeferredSeatAllocator;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Events\SeatMapUpdated;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Remplace le véhicule technique de planification par un car réel sur un
 * voyage matérialisé, après vérification de l'activité, de l'assurance, de
 * l'affectation à la gare, de la capacité et du plan de sièges.
 *
 * Déclenche ensuite l'allocation différée des sièges pour les billets vendus
 * sans numéro de place (ventes quantity_only), puis positionne
 * operational_ready = true.
 *
 * Idempotent et atomique : tout échec laisse le voyage dans son état précédent.
 */
final class AssignRealVehicleToTrip
{
    public function __construct(
        private readonly TripCapacityService $capacity,
        private readonly DeferredSeatAllocator $allocator,
        private readonly VehiclePoolService $vehiclePool,
    ) {}

    /**
     * @throws TicketingRuleViolation si le car ne peut pas être affecté
     */
    public function assign(Trip $trip, Vehicle $vehicle, ?User $actor = null, ?string $reason = null): Trip
    {
        $actorId = $actor?->id;

        if ($vehicle->is_placeholder) {
            throw new TicketingRuleViolation(
                'placeholder_vehicle_forbidden',
                'Un véhicule technique de planification ne peut pas être affecté comme car réel.'
            );
        }

        if (! $vehicle->active) {
            throw new TicketingRuleViolation(
                'vehicle_inactive',
                'Ce véhicule est inactif et ne peut pas être affecté à un voyage.'
            );
        }

        if ($vehicle->isInsuranceExpired($trip->departure_at)) {
            throw new TicketingRuleViolation(
                'vehicle_insurance_expired',
                'L’assurance de ce véhicule est expirée à la date de départ du voyage ('
                    .($vehicle->insurance_expiry_date?->format('d/m/Y') ?? 'inconnue').').'
            );
        }

        // Point G/4 : le pool de la gare est imposé CÔTÉ SERVEUR — un appel
        // HTTP direct ne peut pas injecter un véhicule hors pool. Pour un
        // admin, la contrainte de gare est levée mais les règles métier de
        // capacité et d'exploitation s'appliquent toujours.
        try {
            $this->vehiclePool->assertVehicleAllowedForTrip($trip, $vehicle, $actor?->isAdmin());
        } catch (\DomainException $e) {
            throw new TicketingRuleViolation('vehicle_not_in_pool', $e->getMessage());
        }

        // Capacité : le car doit pouvoir accueillir tous les engagements actifs
        // (billets émis, ventes sans siège, retours rattachés), pas seulement
        // les occupations physiques.
        $vehicleCapacity = $vehicle->seat_count ?? $vehicle->vehicleType?->seat_count ?? 0;

        // Vérification rapide AVANT transaction (UX) — la vérification
        // définitive est refaite SOUS VERROU dans assign().
        if ($vehicleCapacity > 0 && $this->capacity->activeEngagements($trip) > $vehicleCapacity) {
            throw new TicketingRuleViolation(
                'vehicle_too_small',
                "Ce car ({$vehicleCapacity} places) ne peut pas accueillir les engagements actifs du voyage. Réaffectez ou annulez des engagements avant de changer de car."
            );
        }

        // Le véhicule réel doit être affecté à la gare d'origine (si le
        // mécanisme d'affectation de gare est utilisé).
        $hasStationAssignment = $vehicle->stationAssignments()
            ->where('station_id', $trip->origin_station_id)
            ->where('active', true)
            ->exists();

        if ($trip->origin_station_id && ! $hasStationAssignment) {
            // Non bloquant : beaucoup de flottes n'utilisent pas d'affectation de gare.
            Log::info('AssignRealVehicleToTrip: véhicule sans affectation explicite à la gare d\'origine', [
                'trip_id' => $trip->id,
                'vehicle_id' => $vehicle->id,
                'station_id' => $trip->origin_station_id,
            ]);
        }

        return DB::transaction(function () use ($trip, $vehicle, $actorId, $reason, $vehicleCapacity) {
            // Verrouille le voyage et sérialise avec les ventes concurrentes.
            $locked = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->vehicle_id === $vehicle->id && ! $locked->isAwaitingRealVehicle()) {
                // Déjà affecté : on s'assure seulement des flags, opération idempotente.
                if (! $locked->operational_ready) {
                    $locked->forceFill(['operational_ready' => true])->save();
                }

                return $locked->fresh();
            }

            // Vérification de capacité SOUS VERROU : entre la pré-vérification
            // et ici, des ventes concurrentes ont pu consommer la capacité.
            if ($vehicleCapacity > 0 && $this->capacity->activeEngagements($locked) > $vehicleCapacity) {
                throw new TicketingRuleViolation(
                    'vehicle_too_small',
                    "Ce car ({$vehicleCapacity} places) ne peut pas accueillir les engagements actifs du voyage. Réaffectez ou annulez des engagements avant de changer de car."
                );
            }

            $previousVehicleId = $locked->vehicle_id;
            $wasPlaceholder = $locked->vehicle?->is_placeholder === true || $locked->isAwaitingRealVehicle();

            $locked->forceFill([
                'vehicle_id' => $vehicle->id,
                'sales_ready' => true,
                'operational_ready' => false, // recalculé après allocation
                'vehicle_assignment_policy' => $locked->vehicle_assignment_policy ?: 'require_real_vehicle',
            ])->save();

            // Allocation différée des sièges pour les billets vendus sans place.
            $allocated = $this->allocator->allocate($locked);

            $locked->forceFill([
                'operational_ready' => true,
                'seat_assignment_version' => $locked->seat_assignment_version + 1,
                'settings' => array_merge($locked->settings ?? [], [
                    'real_vehicle_assigned_at' => now()->toDateTimeString(),
                    'real_vehicle_assigned_by' => $actorId,
                    'real_vehicle_assignment_reason' => $reason,
                    'previous_vehicle_id' => $previousVehicleId,
                    'was_placeholder' => $wasPlaceholder,
                ]),
            ])->save();

            // Diffusion de la nouvelle carte des sièges.
            try {
                $this->broadcastSeatMap($locked, $allocated);
            } catch (\Throwable $e) {
                Log::warning('AssignRealVehicleToTrip: échec broadcast SeatMapUpdated', ['error' => $e->getMessage()]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Vérifie (SANS AUCUN EFFET DE BORD) si un car peut être affecté au voyage.
     *
     * N'appelle jamais assign() : aucune donnée n'est modifiée, aucune
     * allocation n'est déclenchée, aucun broadcast n'est émis.
     */
    public function canAssign(Trip $trip, Vehicle $vehicle): bool
    {
        if ($vehicle->is_placeholder || ! $vehicle->active) {
            return false;
        }

        if ($vehicle->isInsuranceExpired($trip->departure_at)) {
            return false;
        }

        $vehicleCapacity = $vehicle->seat_count ?? $vehicle->vehicleType?->seat_count ?? 0;

        if ($vehicleCapacity <= 0) {
            return false;
        }

        return $this->capacity->activeEngagements($trip) <= $vehicleCapacity;
    }

    private function broadcastSeatMap(Trip $trip, array $allocated): void
    {
        $broadcastTrip = Trip::with([
            'route.routeStopOrders',
            'originStation',
            'destinationStation',
            'vehicle.vehicleType',
        ])->find($trip->id);

        if (! $broadcastTrip) {
            return;
        }

        // Les éléments alloués sont des TicketJourney (source canonique) :
        // on lit le siège et le trajet depuis le DROIT, jamais depuis le billet.
        $changedSeats = array_map(fn ($journey) => [
            'seat_number' => $journey->seat_number,
            'status' => 'occupied',
            'ticket_id' => $journey->ticket_id,
            'journey_id' => $journey->id,
            'to_station_id' => $journey->to_station_id,
        ], $allocated);

        if ($changedSeats === []) {
            return;
        }

        event(new SeatMapUpdated(
            $broadcastTrip,
            $changedSeats,
            'vehicle.assigned',
            $trip->origin_station_id
        ));
    }
}
