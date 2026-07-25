<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine if the user can view any tickets.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'seller', 'accountant'], true);
    }

    /**
     * Determine if the user can view the specific ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        if (in_array($user->role, ['admin', 'supervisor', 'accountant'], true)) {
            return true;
        }

        if ($user->role === 'seller') {
            if ($ticket->seller_id === $user->id) {
                return true;
            }

            $activeStationIds = $user->getActiveStationIds();

            return $ticket->from_station_id && in_array($ticket->from_station_id, $activeStationIds, true);
        }

        return false;
    }

    /**
     * Determine if the user can print the ticket.
     */
    public function print(User $user, Ticket $ticket): bool
    {
        if (in_array($user->role, ['admin', 'supervisor'], true)) {
            return true;
        }

        if ($user->role === 'seller') {
            if ($ticket->seller_id === $user->id) {
                return true;
            }

            $activeStationIds = $user->getActiveStationIds();

            return $ticket->from_station_id && in_array($ticket->from_station_id, $activeStationIds, true);
        }

        return false;
    }

    /**
     * Determine if the user can export PDF tickets.
     */
    public function exportPdf(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor', 'seller', 'accountant'], true);
    }

    /**
     * Determine if the user can cancel the ticket.
     */
    public function cancel(User $user, Ticket $ticket): bool
    {
        if (in_array($user->role, ['admin', 'supervisor'], true)) {
            return true;
        }

        if ($user->role === 'seller') {
            return $ticket->seller_id === $user->id;
        }

        return false;
    }
}
