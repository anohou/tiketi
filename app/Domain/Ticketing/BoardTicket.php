<?php

namespace App\Domain\Ticketing;

use App\Models\CrewMember;
use App\Models\Ticket;
use App\Models\Trip;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class BoardTicket
{
    public function execute(CrewMember $actor, Trip $trip, Ticket $ticket, ?CarbonInterface $occurredAt = null): Ticket
    {
        $occurredAt ??= now();

        return DB::transaction(function () use ($actor, $trip, $ticket, $occurredAt) {
            $lockedTrip = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $lockedTicket = Ticket::with(['connection', 'compensations'])
                ->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedTrip->status, ['arrived', 'cancelled'], true)) {
                throw new TicketingRuleViolation('trip_terminal', 'Ce voyage ne permet plus d’embarquement.', 409);
            }
            if ($lockedTicket->status !== 'issued') {
                throw new TicketingRuleViolation('ticket_invalid_status', 'Ce ticket est annulé, remboursé ou invalide.', 409);
            }
            $refunded = $lockedTicket->compensations->contains(
                fn ($item) => $item->status === 'executed' && $item->compensation_type === 'refund'
            );
            if ($refunded) {
                throw new TicketingRuleViolation('ticket_refunded', 'Ce ticket a déjà été remboursé.', 409);
            }

            $connection = $lockedTicket->connection?->trip_id === $lockedTrip->id ? $lockedTicket->connection : null;
            if ($lockedTicket->trip_id !== $lockedTrip->id && ! $connection) {
                throw new TicketingRuleViolation('wrong_trip', 'Ce ticket n’est pas affecté à ce voyage.', 409);
            }
            if ($connection && ! in_array($connection->status, ['assigned', 'boarded'], true)) {
                throw new TicketingRuleViolation('connection_not_assigned', 'La correspondance n’est pas affectée à ce voyage.', 409);
            }
            if (($connection && $connection->boarded_at) || (! $connection && $lockedTicket->boarded_at)) {
                throw new TicketingRuleViolation('already_boarded', 'Ce passager a déjà été embarqué sur ce segment.', 409);
            }

            $futureTolerance = (int) config('transport.boarding.future_tolerance_minutes', 5);
            $pastWindow = (int) config('transport.boarding.past_window_hours', 24);
            if ($occurredAt->isAfter(now()->addMinutes($futureTolerance))) {
                throw new TicketingRuleViolation('boarding_time_in_future', 'L’heure d’embarquement est trop éloignée dans le futur.');
            }
            if ($occurredAt->isBefore(now()->subHours($pastWindow))) {
                throw new TicketingRuleViolation('boarding_time_too_old', 'L’heure d’embarquement est en dehors de la fenêtre autorisée.');
            }

            if ($connection) {
                $connection->update(['status' => 'boarded', 'boarded_at' => $occurredAt, 'boarded_by' => $actor->id]);
            } else {
                $lockedTicket->update(['boarded_at' => $occurredAt, 'boarded_by' => $actor->id]);
            }

            return $lockedTicket->fresh(['fromStation', 'toStation', 'boardedBy', 'connection.boardedBy']);
        });
    }
}
