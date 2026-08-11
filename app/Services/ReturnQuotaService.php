<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\TicketJourney;
use Illuminate\Support\Facades\DB;

/**
 * Quota des retours garantis sur un créneau de programme.
 *
 * Un retour `fixed_schedule` vendu sur un programme avec quota garanti
 * (confirmed_return_quota) consomme une unité du quota du programme pour la
 * date de service choisie. Les retours à date flexible ou ouverts ne sont pas
 * concernés (aucune place garantie avant affectation).
 */
final class ReturnQuotaService
{
    /**
     * Retours garantis déjà vendus pour un programme et une date de service.
     */
    public function guaranteedUsed(DepartureSchedule $schedule, string $serviceDate): int
    {
        return TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)
            ->where('selection_mode', TicketJourney::SELECTION_FIXED_SCHEDULE)
            ->where('departure_schedule_id', $schedule->id)
            ->whereDate('desired_travel_date', $serviceDate)
            ->whereNotIn('status', [
                TicketJourney::STATUS_CANCELLED,
                TicketJourney::STATUS_EXPIRED,
            ])
            ->count();
    }

    /**
     * Quota restant pour un programme et une date (null = pas de quota configuré).
     */
    public function remaining(DepartureSchedule $schedule, string $serviceDate): ?int
    {
        if ($schedule->confirmed_return_quota === null) {
            return null;
        }

        return max(0, $schedule->confirmed_return_quota - $this->guaranteedUsed($schedule, $serviceDate));
    }

    /**
     * Vérifie (sans effet) qu'une réservation de retours garantis est possible.
     *
     * @throws TicketingRuleViolation si le quota est dépassé
     */
    public function assertCanReserve(DepartureSchedule $schedule, string $serviceDate, int $units): void
    {
        $remaining = $this->remaining($schedule, $serviceDate);

        if ($remaining === null) {
            return; // pas de quota garanti : l'horaire est une préférence
        }

        if ($units > $remaining) {
            throw new TicketingRuleViolation(
                'return_quota_exceeded',
                "Le quota de billets prioritaires est dépassé pour ce créneau ({$remaining} restant(s))."
            );
        }
    }

    /**
     * Réserve atomiquement des unités du quota (appelée dans la transaction de vente).
     *
     * @return int quota restant
     *
     * @throws TicketingRuleViolation si le quota est dépassé
     */
    public function reserve(DepartureSchedule $schedule, string $serviceDate, int $units): int
    {
        if ($units <= 0) {
            return $this->remaining($schedule, $serviceDate) ?? PHP_INT_MAX;
        }

        return DB::transaction(function () use ($schedule, $serviceDate, $units) {
            // Verrouille la ligne du programme pour sérialiser les ventes concurrentes.
            $locked = DepartureSchedule::whereKey($schedule->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw new TicketingRuleViolation('schedule_gone', 'Ce programme de départ n’existe plus.');
            }

            $this->assertCanReserve($locked, $serviceDate, $units);

            return $this->remaining($locked, $serviceDate);
        });
    }
}
