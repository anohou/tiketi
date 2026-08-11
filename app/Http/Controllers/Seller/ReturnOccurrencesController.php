<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Concerns\ChecksStationAccess;
use App\Http\Controllers\Controller;
use App\Models\DepartureSchedule;
use App\Models\Station;
use App\Services\DepartureScheduleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Calendrier des OCCURRENCES de retour pour le vendeur (point 4).
 *
 * Contrairement au calendrier d'administration (aperçu théorique), cet
 * endpoint retourne uniquement les occurrences réellement exploitables pour
 * un programme de retour. Les jours hors programme et les occurrences
 * annulées ou suspendues ne sont jamais proposés à la vente :
 *
 *   GET /seller/departure-schedules/{schedule}/return-occurrences?from=&to=
 *
 * Réponse :
 *   days: [{ date, occurrences: [{
 *     service_date, departure_time, schedule_id, available
 *   }]}]
 */
class ReturnOccurrencesController extends Controller
{
    use ChecksStationAccess;

    public function __construct(
        private readonly DepartureScheduleCalendar $calendar,
    ) {}

    public function __invoke(Request $request, DepartureSchedule $schedule): JsonResponse
    {
        $originStation = Station::findOrFail($schedule->origin_station_id);
        $this->assertUserCanAccessStation($originStation);

        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = CarbonImmutable::parse($request->input('from'));
        $to = CarbonImmutable::parse($request->input('to'))->endOfDay();

        $limit = min((int) ($request->input('limit') ?? 31), 370);
        $days = [];
        $day = $from->copy();

        while ($day->lte($to) && count($days) < $limit) {
            $occurrences = $this->calendar->occurrencesForDate($schedule, $day);

            if ($occurrences->isNotEmpty()) {
                $days[] = [
                    'date' => $day->toDateString(),
                    'label' => $day->isoFormat('dddd D MMMM YYYY'),
                    'occurrences' => $occurrences->map(fn ($occ) => [
                        'service_date' => $occ['service_date'],
                        'departure_time' => $occ['departure_time']->format('H:i'),
                        'schedule_id' => $schedule->id,
                        'capacity' => $occ['capacity'],
                        'available' => true,
                        'exception_type' => $occ['exception']?->type,
                        'reason' => null,
                    ])->values(),
                ];
            }

            $day = $day->addDay();
        }

        return response()->json([
            'schedule_id' => $schedule->id,
            'origin_station_id' => $schedule->origin_station_id,
            'destination_station_id' => $schedule->destination_station_id,
            'days' => $days,
        ]);
    }
}
