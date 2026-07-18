<?php

namespace App\Domain\Trips;

use App\Models\CrewMember;
use App\Models\Trip;
use App\Models\VehicleCrewAssignment;

final class CrewTripAccessPolicy
{
    public function canAccess(CrewMember $crewMember, Trip $trip): bool
    {
        if (! $trip->vehicle_id || ! $trip->departure_at) {
            return false;
        }

        $query = VehicleCrewAssignment::query()
            ->where('crew_member_id', $crewMember->id)
            ->where('vehicle_id', $trip->vehicle_id)
            ->where(function ($assignments) use ($trip) {
                $assignments->where(fn ($atDeparture) => $atDeparture->atDate($trip->departure_at));

                if (in_array($trip->status, ['boarding', 'delayed', 'departed'], true)) {
                    $assignments->orWhere(fn ($currentlyAssigned) => $currentlyAssigned->atDate(now()));
                }
            });

        return $query->exists();
    }
}
