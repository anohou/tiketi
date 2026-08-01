<?php

namespace App\Domain\Trips;

use App\Models\CrewMember;
use App\Models\OperationalSetting;
use App\Models\Trip;
use App\Models\VehicleCrewAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CrewTripVisibility
{
    public function __construct(private readonly CrewTripAccessPolicy $access) {}

    public function operationalWindow(?Carbon $instant = null): array
    {
        $instant ??= now();
        $settings = OperationalSetting::current();
        $startHour = $settings->operationalDayStartHour();
        $start = $instant->copy()->startOfDay()->addHours($startHour);
        if ($instant->lt($start)) {
            $start->subDay();
        }
        $end = $start->copy()->addHours($settings->scheduledTripLookaheadHours());

        return [$start, $end];
    }

    public function apply(Builder $query, CrewMember $crewMember): Builder
    {
        [$windowStart, $windowEnd] = $this->operationalWindow();
        $activeStart = now()->subHours((int) config('transport.operations.active_trip_lookback_hours', 48));

        $vehicleIds = VehicleCrewAssignment::query()
            ->where('crew_member_id', $crewMember->id)
            ->overlapping($activeStart, $windowEnd)
            ->pluck('vehicle_id');

        return $query
            ->whereIn('vehicle_id', $vehicleIds)
            ->where(function ($trips) use ($windowStart, $windowEnd, $activeStart) {
                $trips->where(function ($scheduled) use ($windowStart, $windowEnd) {
                    $scheduled->where('status', 'scheduled')
                        ->where('departure_at', '>=', $windowStart)
                        ->where('departure_at', '<', $windowEnd);
                })->orWhere(function ($active) use ($activeStart, $windowEnd) {
                    $active->whereIn('status', ['boarding', 'delayed', 'departed'])
                        ->where('departure_at', '>=', $activeStart)
                        ->where('departure_at', '<', $windowEnd);
                });
            });
    }

    public function filter(Collection $trips, CrewMember $crewMember): Collection
    {
        return $trips->filter(fn (Trip $trip) => $this->access->canAccess($crewMember, $trip))->values();
    }
}
