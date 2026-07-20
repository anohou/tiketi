<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TidsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Trip $trip,
        public string $action = 'trip.updated',
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $this->trip->loadMissing('route.routeStopOrders');

        $stationIds = [
            $this->trip->origin_station_id,
            $this->trip->destination_station_id,
            $this->trip->route?->origin_station_id,
            $this->trip->route?->destination_station_id,
            ...($this->trip->route?->routeStopOrders->pluck('station_id')->all() ?? []),
        ];

        return collect($stationIds)
            ->filter()
            ->unique()
            ->map(fn (string $stationId) => new Channel('tids.station.'.$stationId))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'TidsUpdated';
    }

    public function broadcastWith(): array
    {
        // The public board only needs an invalidation signal. Detailed ticket
        // and seat data remains restricted to the authenticated channels.
        return [
            'trip_id' => $this->trip->id,
            'action' => $this->action,
        ];
    }
}
