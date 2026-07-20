<?php

namespace App\Domain\Trips;

use App\Models\CrewMember;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripTimingService;
use Illuminate\Support\Facades\DB;

final class TripStateMachine
{
    private const TRANSITIONS = [
        'scheduled' => ['boarding', 'delayed', 'cancelled'],
        'delayed' => ['scheduled', 'boarding', 'cancelled'],
        'boarding' => ['delayed', 'departed', 'cancelled'],
        'departed' => ['arrived'],
        'arrived' => [],
        'cancelled' => [],
    ];

    public function __construct(private readonly TripTimingService $timing) {}

    public function can(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function transition(Trip $trip, string $requestedStatus, User|CrewMember|null $actor = null, string $source = 'unknown', ?string $reason = null): Trip
    {
        $target = TripStatus::normalize($requestedStatus);

        return DB::transaction(function () use ($trip, $target, $actor, $source, $reason) {
            $locked = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status ?: 'scheduled';

            if (! $this->can($from, $target)) {
                throw new InvalidTripTransition("Transition de voyage interdite : {$from} → {$target}.");
            }
            if ($from === $target) {
                return $locked;
            }

            $updated = match ($target) {
                'departed' => $this->timing->markDeparted($locked),
                'arrived' => $this->timing->markArrived($locked),
                'cancelled' => $this->timing->markCancelled($locked),
                default => tap($locked, function (Trip $model) use ($target) {
                    $model->status = $target;
                    $model->save();
                })->fresh(),
            };

            $log = $updated->statusLogs()->latest('created_at')->first();
            if ($log) {
                $log->update([
                    'changed_by_user_id' => $actor instanceof User ? $actor->id : $log->changed_by_user_id,
                    'changed_by_crew_member_id' => $actor instanceof CrewMember ? $actor->id : $log->changed_by_crew_member_id,
                    'note' => json_encode(array_filter([
                        'from' => $from,
                        'source' => $source,
                        'reason' => $reason,
                    ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }

            return $updated->fresh();
        });
    }
}
