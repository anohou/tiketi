<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\OkohiTicketOutbox;
use App\Models\TicketJourney;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changement de date / heure / programme d'un retour (§11).
 *
 * - Retire l'ancienne affectation (libère la place et l'historique), puis
 *   applique la nouvelle préférence atomiquement.
 * - Réévalue le quota du programme (un fixed_schedule vers un autre programme
 *   libère l'ancien quota et consomme le nouveau).
 * - Okohi reçoit la mise à jour.
 */
final class ChangeReturnPreference
{
    public function __construct(
        private readonly ReturnJourneyAllocator $allocator,
        private readonly ReturnQuotaService $quota,
        private readonly OkohiTicketPublisher $publisher,
    ) {}

    /**
     * @param  array{desired_travel_date?: string|null, desired_departure_time?: string|null, departure_schedule_id?: string|null}  $preferences
     */
    public function change(TicketJourney $journey, User $actor, array $preferences): TicketJourney
    {
        return DB::transaction(function () use ($journey, $actor, $preferences) {
            $locked = TicketJourney::whereKey($journey->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isReturn()) {
                throw new TicketingRuleViolation('not_return', 'Seul un droit retour peut être modifié.', 422);
            }

            if (in_array($locked->status, [TicketJourney::STATUS_BOARDED, TicketJourney::STATUS_COMPLETED], true)) {
                throw new TicketingRuleViolation('journey_consumed', 'Ce retour a déjà été consommé.', 409);
            }

            if ($locked->status === TicketJourney::STATUS_CANCELLED) {
                throw new TicketingRuleViolation('journey_cancelled', 'Ce retour a été annulé : aucun changement possible.', 409);
            }

            $newScheduleId = $preferences['departure_schedule_id'] ?? $locked->departure_schedule_id;
            $newDate = $preferences['desired_travel_date'] ?? $locked->desired_travel_date?->toDateString();
            $newTime = $preferences['desired_departure_time'] ?? ($locked->desired_departure_time?->format('H:i'));

            // Cible fixed_schedule : un programme est fourni (ou déjà présent).
            $becomingFixedSchedule = $newScheduleId !== null && $newScheduleId !== '';

            if ($becomingFixedSchedule) {
                if (! $newDate) {
                    throw new TicketingRuleViolation('return_schedule_required', 'Un retour à créneau précis exige un programme et une date.', 422);
                }

                $schedule = DepartureSchedule::findOrFail($newScheduleId);
                $this->quota->assertCanReserve($schedule, $newDate, 1);
            }

            // Retire l'ancienne affectation (libère place + quota implicite).
            if ($locked->trip_id !== null) {
                $this->allocator->unassign($locked, $actor, 'preference_change');
                $locked->refresh();
            }

            $updates = ['settings' => $locked->settings ?? []];

            if (array_key_exists('desired_travel_date', $preferences)) {
                $updates['desired_travel_date'] = $preferences['desired_travel_date'];
            }
            if (array_key_exists('desired_departure_time', $preferences)) {
                $updates['desired_departure_time'] = $preferences['desired_departure_time'];
            }
            if (array_key_exists('departure_schedule_id', $preferences)) {
                $updates['departure_schedule_id'] = $preferences['departure_schedule_id'];
            }

            // Bascule le mode de sélection vers fixed_schedule si un programme
            // est choisi, sinon conserve le mode actuel.
            if ($becomingFixedSchedule) {
                $updates['selection_mode'] = TicketJourney::SELECTION_FIXED_SCHEDULE;
            }

            // Le statut redevient cohérent avec le mode.
            $updates['status'] = match ($locked->selection_mode === TicketJourney::SELECTION_FIXED_SCHEDULE || $becomingFixedSchedule) {
                true => TicketJourney::STATUS_AWAITING_TRIP,
                false => match ($locked->selection_mode) {
                    TicketJourney::SELECTION_DATE_FLEXIBLE => TicketJourney::STATUS_READY,
                    default => TicketJourney::STATUS_PENDING,
                },
            };

            $locked->update($updates);

            // Okohi : mise à jour (en file, non bloquante).
            try {
                $this->publisher->enqueue($locked->ticket, OkohiTicketOutbox::OPERATION_UPDATE);
            } catch (\Throwable $e) {
                // La file ne fait jamais échouer le changement.
            }

            return $locked->fresh();
        });
    }
}
