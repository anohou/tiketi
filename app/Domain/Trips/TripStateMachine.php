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

            // Barrière absolue : embarquement et départ exigent un car réel
            // affecté et un voyage validé opérationnellement.
            if (in_array($target, ['boarding', 'departed'], true)) {
                if ($locked->isAwaitingRealVehicle() || $locked->hasPlaceholderVehicle()) {
                    throw new InvalidTripTransition(
                        'Un car réel doit être affecté avant l’embarquement et le départ. Le véhicule actuel est un véhicule technique de planification.'
                    );
                }
                if (! $locked->isOperationalReady()) {
                    throw new InvalidTripTransition(
                        'Ce voyage n’est pas encore validé pour l’exploitation (car réel et prérequis manquants).'
                    );
                }
            }

            $updated = match ($target) {
                'departed' => $this->timing->markDeparted($locked),
                'arrived' => $this->timing->markArrived($locked),
                'cancelled' => tap($this->timing->markCancelled($locked), function (Trip $trip) {
                    // §11 : les retours affectés à ce voyage reviennent dans le pool
                    // avec priorité (libération des places, historique, Okohi).
                    app(\App\Services\ReleaseTripReturns::class)->release($trip);
                }),
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
