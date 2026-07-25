<?php

namespace App\Policies;

use App\Models\TicketCompensation;
use App\Models\User;

class CompensationPolicy
{
    /**
     * Determine if the user can view any compensations.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }

    /**
     * Determine if the user can view a specific compensation.
     */
    public function view(User $user, TicketCompensation $compensation): bool
    {
        if (in_array($user->role, ['admin', 'supervisor'], true)) {
            return true;
        }

        return $compensation->requested_by === $user->id;
    }

    /**
     * Determine if the user can approve a compensation.
     */
    public function approve(User $user, TicketCompensation $compensation): bool
    {
        return in_array($user->role, ['admin', 'supervisor'], true);
    }
}
