<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\OkohiTicketOutbox;
use App\Models\TicketJourney;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Prolongation d'un retour expiré ou proche de l'expiration (§11).
 *
 * - Un retour expiré ne permet plus aucun embarquement sans prolongation
 *   autorisée et auditée : auteur, motif et nouvelle date limite sont
 *   consignés dans settings.prolongation (historique).
 * - Okohi reçoit la mise à jour de validité.
 */
final class ExtendReturnJourney
{
    public const MAX_PROLONGATION_DAYS = 90;

    public function __construct(
        private readonly OkohiTicketPublisher $publisher,
    ) {}

    public function extend(TicketJourney $journey, User $actor, CarbonInterface $newValidUntil, string $reason): TicketJourney
    {
        return DB::transaction(function () use ($journey, $actor, $newValidUntil, $reason) {
            $locked = TicketJourney::whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isReturn()) {
                throw new TicketingRuleViolation('not_return', 'Seul un droit retour peut être prolongé.', 422);
            }

            if ($locked->status === TicketJourney::STATUS_BOARDED || $locked->status === TicketJourney::STATUS_COMPLETED) {
                throw new TicketingRuleViolation('journey_consumed', 'Ce retour a déjà été consommé.', 409);
            }

            if ($locked->status === TicketJourney::STATUS_CANCELLED) {
                throw new TicketingRuleViolation('journey_cancelled', 'Ce retour a été annulé : aucune prolongation possible.', 409);
            }

            $maxDate = now()->addDays(self::MAX_PROLONGATION_DAYS);
            if ($newValidUntil->gt($maxDate)) {
                throw new TicketingRuleViolation(
                    'prolongation_too_long',
                    "La prolongation ne peut pas dépasser ".self::MAX_PROLONGATION_DAYS." jours.",
                    422,
                );
            }

            // Historique des prolongations (audit).
            $settings = $locked->settings ?? [];
            $prolongations = data_get($settings, 'prolongations', []);
            $prolongations[] = [
                'authorized_by' => $actor->id,
                'reason' => $reason,
                'previous_valid_until' => $locked->valid_until?->toIso8601String(),
                'new_valid_until' => $newValidUntil->toIso8601String(),
                'authorized_at' => now()->toIso8601String(),
            ];
            data_set($settings, 'prolongations', $prolongations);
            data_set($settings, 'extended', true);

            $locked->update([
                'valid_until' => $newValidUntil->endOfDay(),
                'status' => $locked->status === TicketJourney::STATUS_EXPIRED
                    ? ($locked->selection_mode === TicketJourney::SELECTION_FIXED_SCHEDULE
                        ? TicketJourney::STATUS_AWAITING_TRIP
                        : ($locked->selection_mode === TicketJourney::SELECTION_DATE_FLEXIBLE
                            ? TicketJourney::STATUS_READY
                            : TicketJourney::STATUS_PENDING))
                    : $locked->status,
                'settings' => $settings,
            ]);

            // Okohi : mise à jour de validité (en file, non bloquante).
            try {
                $this->publisher->enqueue($locked->ticket, OkohiTicketOutbox::OPERATION_UPDATE);
            } catch (\Throwable $e) {
                // La file ne fait jamais échouer la prolongation.
            }

            return $locked->fresh();
        });
    }
}
