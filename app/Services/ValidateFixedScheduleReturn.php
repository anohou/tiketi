<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\DepartureScheduleException;
use Carbon\CarbonImmutable;

/**
 * Validation d'un retour à créneau précis (fixed_schedule) — point E.
 *
 * Côté serveur uniquement :
 * - le programme est actif et valide à la date choisie ;
 * - le trajet correspond EXACTEMENT au trajet inverse de l'aller ;
 * - le programme circule le jour choisi (jours de circulation) ;
 * - aucune exception cancelled / suspended ce jour-là ;
 * - l'heure est calculée depuis le programme et son éventuelle exception
 *   time_changed — jamais une heure arbitraire envoyée par le client ;
 * - le quota garanti est respecté si le départ est garanti.
 */
final class ValidateFixedScheduleReturn
{
    public function __construct(private readonly ReturnQuotaService $quota) {}

    /**
     * @return array{schedule: DepartureSchedule, service_date: string, departure_time: string, guaranteed: bool}
     */
    public function validate(string $scheduleId, string $returnDate, string $outboundFromStationId, string $outboundToStationId, string $timezone = 'UTC'): array
    {
        $schedule = DepartureSchedule::with('exceptions')->find($scheduleId);

        if (! $schedule || ! $schedule->active) {
            throw new TicketingRuleViolation('schedule_inactive', 'Ce programme de départ n’est plus actif.', 422);
        }

        // Le programme doit être valide à la date de service choisie.
        // Requête STRICTEMENT restreinte au programme sélectionné : le fait
        // qu'un AUTRE programme soit valide ce jour-là ne doit jamais faire
        // accepter un programme hors période (bug G2).
        $date = CarbonImmutable::parse($returnDate)->toDateString();

        $validOnDate = DepartureSchedule::whereKey($schedule->id)
            ->activeOn($date)
            ->exists();

        if (! $validOnDate) {
            throw new TicketingRuleViolation(
                'schedule_not_valid_on_date',
                'Ce programme n’est pas en vigueur à la date choisie.',
                422,
            );
        }

        // Trajet inverse exact : le retour part de la destination de l'aller
        // et arrive à son origine.
        if ($schedule->origin_station_id !== $outboundToStationId || $schedule->destination_station_id !== $outboundFromStationId) {
            throw new TicketingRuleViolation(
                'incompatible_return_route',
                'Ce programme de retour ne correspond pas au trajet inverse de l’aller.',
                422,
            );
        }

        // Le programme doit circuler le jour de la semaine choisi.
        $dayOfWeek = (int) CarbonImmutable::parse($date)->isoWeekday();

        if (! $schedule->runsOnDay($dayOfWeek)) {
            throw new TicketingRuleViolation(
                'schedule_not_on_day',
                'Ce programme ne circule pas le jour choisi.',
                422,
            );
        }

        // Exception du jour : annulé / suspendu → refus ; time_changed →
        // l'heure affichée est celle de l'exception.
        $exception = $schedule->exceptionFor($date);

        if ($exception && in_array($exception->type, [
            DepartureScheduleException::TYPE_CANCELLED,
            DepartureScheduleException::TYPE_SUSPENDED,
        ], true)) {
            throw new TicketingRuleViolation(
                'schedule_exception',
                'Ce départ est annulé ou suspendu à la date choisie (exception du programme).',
                422,
            );
        }

        // Heure calculée depuis le programme (jamais depuis le client).
        $departureTime = $exception?->type === DepartureScheduleException::TYPE_TIME_CHANGED && $exception->replacement_time
            ? $exception->replacement_time->format('H:i')
            : $schedule->departure_time?->format('H:i');

        if (! $departureTime) {
            throw new TicketingRuleViolation('schedule_no_time', 'Ce programme ne possède pas d’horaire exploitable.', 422);
        }

        // Quota garanti (le programme étant garanti quand un quota est défini).
        $guaranteed = $schedule->confirmed_return_quota !== null;
        $this->quota->assertCanReserve($schedule, $date, 1);

        return [
            'schedule' => $schedule,
            'service_date' => $date,
            'departure_time' => $departureTime,
            'guaranteed' => $guaranteed,
        ];
    }
}
