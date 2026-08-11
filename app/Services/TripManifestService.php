<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Manifeste d'un voyage : correspondance ticket / passager / siège, pour
 * l'impression et l'affichage à l'embarquement. Unifie les billets
 * historiques (tickets.*) et les droits de voyage (ticket_journeys).
 */
final class TripManifestService
{
    /**
     * Lignes du manifeste pour un voyage.
     *
     * @return Collection<int, array{
     *     journey_id: string|null,
     *     ticket_id: string,
     *     ticket_number: string,
     *     direction: string,
     *     passenger_name: string|null,
     *     passenger_phone: string|null,
     *     seat_number: int|null,
     *     seat_assignment_status: string,
     *     status: string,
     *     boarded: bool,
     *     from_station: string|null,
     *     to_station: string|null,
     * }>
     */
    public function forTrip(Trip $trip): Collection
    {
        // Droits de voyage affectés à ce voyage (source canonique).
        $journeys = TicketJourney::with(['ticket', 'fromStation', 'toStation'])
            ->where('trip_id', $trip->id)
            ->whereIn('status', [
                TicketJourney::STATUS_ASSIGNED,
                TicketJourney::STATUS_BOARDED,
                TicketJourney::STATUS_COMPLETED,
            ])
            ->get();

        $manifest = $journeys->map(function (TicketJourney $journey) {
            $ticket = $journey->ticket;

            return [
                'journey_id' => $journey->id,
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'direction' => $journey->direction,
                'passenger_name' => $ticket->passenger_name,
                'passenger_phone' => $ticket->passenger_phone,
                'seat_number' => $journey->seat_number,
                'seat_assignment_status' => $journey->seat_assignment_status,
                'status' => $journey->status,
                // Point 3 : boarded est vrai pour boarded ET completed.
                'boarded' => in_array($journey->status, [
                    TicketJourney::STATUS_BOARDED,
                    TicketJourney::STATUS_COMPLETED,
                ], true),
                'from_station' => $journey->fromStation?->name,
                'to_station' => $journey->toStation?->name,
            ];
        });

        // Billets historiques sans droit (transition) : on les ajoute depuis tickets.*
        $legacy = Ticket::with(['fromStation', 'toStation'])
            ->where('trip_id', $trip->id)
            ->where('status', 'issued')
            ->whereDoesntHave('journeys', function ($q) use ($trip) {
                $q->where('trip_id', $trip->id);
            })
            ->get()
            ->map(function (Ticket $ticket) {
                return [
                    'journey_id' => null,
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'direction' => TicketJourney::DIRECTION_OUTBOUND,
                    'passenger_name' => $ticket->passenger_name,
                    'passenger_phone' => $ticket->passenger_phone,
                    'seat_number' => $ticket->seat_number,
                    'seat_assignment_status' => $ticket->seat_number !== null
                        ? TicketJourney::SEAT_CONFIRMED
                        : TicketJourney::SEAT_UNASSIGNED,
                    // Legacy : pas de droit — boarded depuis ticket.boarded_at.
                    'status' => $ticket->boarded_at ? TicketJourney::STATUS_BOARDED : TicketJourney::STATUS_ASSIGNED,
                    'boarded' => $ticket->boarded_at !== null,
                    'from_station' => $ticket->fromStation?->name,
                    'to_station' => $ticket->toStation?->name,
                ];
            });

        return $manifest
            ->concat($legacy)
            ->sortBy([
                ['seat_number', 'asc'],
                ['ticket_number', 'asc'],
            ])
            ->values();
    }

    /**
     * Nombre de passagers embarqués / total (pour l'affichage).
     *
     * @return array{boarded: int, total: int}
     */
    public function boardingStats(Trip $trip): array
    {
        $manifest = $this->forTrip($trip);

        return [
            'boarded' => $manifest->where('boarded', true)->count(),
            'total' => $manifest->count(),
        ];
    }
}
