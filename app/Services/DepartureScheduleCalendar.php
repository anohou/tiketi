<?php

namespace App\Services;

use App\Models\DepartureSchedule;
use App\Models\DepartureScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Calcule les créneaux d'un programme applicables à une date donnée,
 * SANS matérialiser de voyage. Les exceptions calendaires (annulation,
 * suspension, décalage horaire, changement de capacité) sont appliquées.
 */
final class DepartureScheduleCalendar
{
    /**
     * Créneaux effectifs pour un programme sur une date de service.
     *
     * @return Collection<int, array{
     *     schedule: DepartureSchedule,
     *     service_date: string,
     *     departure_time: CarbonImmutable,
     *     capacity: int|null,
     *     exception: DepartureScheduleException|null,
     *     cancelled: bool,
     * }>
     */
    public function occurrencesForDate(DepartureSchedule $schedule, string|\DateTimeInterface $date): Collection
    {
        $serviceDate = CarbonImmutable::parse($date)->toDateString();

        if (! $this->isApplicable($schedule, $serviceDate)) {
            return collect();
        }

        $exception = $schedule->exceptionFor($serviceDate);

        if ($exception && $exception->preventsService()) {
            return collect();
        }

        $time = $exception?->replacement_time
            ? CarbonImmutable::parse($exception->replacement_time->format('H:i'))
            : CarbonImmutable::parse($schedule->departure_time->format('H:i'));

        $capacity = $exception?->replacement_capacity ?? $schedule->planned_capacity;

        return collect([[
            'schedule' => $schedule,
            'service_date' => $serviceDate,
            'departure_time' => $time,
            'capacity' => $capacity,
            'exception' => $exception,
            'cancelled' => false,
            'unavailable_reason' => null,
        ]]);
    }

    /**
     * Tous les créneaux effectifs d'une collection de programmes pour une date.
     */
    public function occurrencesForDateAcross(iterable $schedules, string|\DateTimeInterface $date): Collection
    {
        return collect($schedules)
            ->flatMap(fn (DepartureSchedule $schedule) => $this->occurrencesForDate($schedule, $date))
            ->values();
    }

    /**
     * Le programme est actif et circule ce jour-là (jour de la semaine,
     * période de validité, hors exceptions définitives).
     */
    public function isApplicable(DepartureSchedule $schedule, string|\DateTimeInterface $date): bool
    {
        $serviceDate = CarbonImmutable::parse($date);

        if (! $schedule->active) {
            return false;
        }

        if ($schedule->valid_from && $serviceDate->lt(CarbonImmutable::parse($schedule->valid_from)->startOfDay())) {
            return false;
        }

        if ($schedule->valid_until && $serviceDate->gt(CarbonImmutable::parse($schedule->valid_until)->endOfDay())) {
            return false;
        }

        if (! $schedule->runsOnDay($serviceDate->dayOfWeekIso)) {
            return false;
        }

        return true;
    }
}
