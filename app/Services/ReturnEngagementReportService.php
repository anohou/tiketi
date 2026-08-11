<?php

namespace App\Services;

use App\Models\DepartureSchedule;
use App\Models\OkohiTicketOutbox;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tableau de bord des programmes et des retours (§15).
 *
 * Mesures par tenant/gare : programmes actifs, retours garantis par créneau,
 * retours à date flexible non répartis, retours ouverts (utilisés/expirés),
 * billets vendus sans siège, taux de remplissage, conflits de capacité.
 */
final class ReturnEngagementReportService
{
    public function report(?string $stationId = null): array
    {
        $schedules = DepartureSchedule::with('exceptions')->where('active', true)
            ->when($stationId, fn ($q) => $q->where('station_id', $stationId))
            ->get();

        $today = Carbon::today()->toDateString();

        $returns = TicketJourney::where('direction', TicketJourney::DIRECTION_RETURN)
            ->whereIn('status', [
                TicketJourney::STATUS_PENDING,
                TicketJourney::STATUS_AWAITING_TRIP,
                TicketJourney::STATUS_READY,
                TicketJourney::STATUS_ASSIGNED,
                TicketJourney::STATUS_BOARDED,
                TicketJourney::STATUS_COMPLETED,
                TicketJourney::STATUS_EXPIRED,
            ])
            ->get();

        // Engagements sans siège : billets quantity_only en cours.
        $seatless = Ticket::where('status', 'issued')
            ->whereNull('seat_number')
            ->count();

        // Voyages de la journée sans car réel.
        $tripsWithoutVehicle = Trip::whereDate('service_date', $today)
            ->orWhereDate('departure_at', $today)
            ->get()
            ->filter(fn (Trip $trip) => $trip->hasPlaceholderVehicle() || $trip->isAwaitingRealVehicle())
            ->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'schedules' => [
                'active' => $schedules->count(),
                'with_quota' => $schedules->whereNotNull('confirmed_return_quota')->count(),
                'allow_planned_capacity' => $schedules->filter(
                    fn (DepartureSchedule $s) => $s->resolvedPolicy() === DepartureSchedule::POLICY_ALLOW_PLANNED_CAPACITY
                )->count(),
            ],
            'returns' => [
                'total' => $returns->count(),
                'fixed_schedule' => $returns->where('selection_mode', TicketJourney::SELECTION_FIXED_SCHEDULE)->count(),
                'date_flexible' => $returns->where('selection_mode', TicketJourney::SELECTION_DATE_FLEXIBLE)->count(),
                'open' => $returns->where('selection_mode', TicketJourney::SELECTION_OPEN)->count(),
                'assigned' => $returns->where('status', TicketJourney::STATUS_ASSIGNED)->count(),
                'awaiting_trip' => $returns->where('status', TicketJourney::STATUS_AWAITING_TRIP)->count(),
                'ready' => $returns->where('status', TicketJourney::STATUS_READY)->count(),
                'boarded' => $returns->where('status', TicketJourney::STATUS_BOARDED)->count(),
                'completed' => $returns->where('status', TicketJourney::STATUS_COMPLETED)->count(),
                'expired' => $returns->where('status', TicketJourney::STATUS_EXPIRED)->count(),
            ],
            'operations' => [
                'seatless_tickets' => $seatless,
                'trips_today_without_real_vehicle' => $tripsWithoutVehicle,
            ],
            'okohi' => [
                'pending' => OkohiTicketOutbox::where('status', OkohiTicketOutbox::STATUS_PENDING)->count(),
                'failed' => OkohiTicketOutbox::where('status', OkohiTicketOutbox::STATUS_FAILED)->count(),
                'delivered' => OkohiTicketOutbox::where('status', OkohiTicketOutbox::STATUS_DELIVERED)->count(),
            ],
            'integrity' => [
                'journeys_without_occupancy_for_confirmed_seat' => $this->countSeatDivergences(),
            ],
            'station_totals' => $this->stationTotals($returns, $schedules),
        ];
    }

    /**
     * Divergences : droits avec siège confirmé mais sans occupation physique.
     */
    private function countSeatDivergences(): int
    {
        $confirmed = TicketJourney::whereIn('seat_assignment_status', [
            TicketJourney::SEAT_CONFIRMED,
            TicketJourney::SEAT_REASSIGNED,
        ])->whereNotNull('seat_number')->whereNotNull('trip_id')->get(['id', 'ticket_id', 'trip_id', 'seat_number']);

        $divergences = 0;
        foreach ($confirmed as $journey) {
            $exists = TripSeatOccupancy::where('trip_id', $journey->trip_id)
                ->where('seat_number', $journey->seat_number)
                ->where('ticket_id', $journey->ticket_id)
                ->exists();

            if (! $exists) {
                $divergences++;
            }
        }

        return $divergences;
    }

    private function stationTotals(Collection $returns, Collection $schedules): Collection
    {
        $byStation = $returns->groupBy('from_station_id')->map(fn (Collection $group) => [
            'total' => $group->count(),
            'open' => $group->where('selection_mode', TicketJourney::SELECTION_OPEN)->count(),
            'expired' => $group->where('status', TicketJourney::STATUS_EXPIRED)->count(),
            'assigned' => $group->where('status', TicketJourney::STATUS_ASSIGNED)->count(),
        ]);

        // Ajoute les gares ayant des programmes mais aucun retour.
        foreach ($schedules->pluck('station_id')->unique() as $stationId) {
            if (! $byStation->has($stationId)) {
                $byStation[$stationId] = ['total' => 0, 'open' => 0, 'expired' => 0, 'assigned' => 0];
            }
        }

        return $byStation;
    }
}
