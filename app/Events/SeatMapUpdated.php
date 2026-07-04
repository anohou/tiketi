<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeatMapUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Trip $trip,
        public array $changedSeats,
        public string $action = 'ticket.updated',
        public ?string $sourceStationId = null,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $this->trip->loadMissing('route.routeStopOrders');

        $channelNames = [
            'trip.'.$this->trip->id,
            'trips.global',
            'network.global',
        ];

        $route = $this->trip->route;
        if ($route?->origin_station_id) {
            $channelNames[] = 'station.'.$route->origin_station_id;
        }

        if ($route?->destination_station_id) {
            $channelNames[] = 'station.'.$route->destination_station_id;
        }

        foreach ($route?->routeStopOrders ?? [] as $stop) {
            if ($stop->station_id) {
                $channelNames[] = 'station.'.$stop->station_id;
            }
        }

        $uniqueNames = array_values(array_unique($channelNames));

        return array_map(
            fn (string $channelName) => new PrivateChannel($channelName),
            $uniqueNames
        );
    }

    public function broadcastAs(): string
    {
        return 'SeatMapUpdated';
    }

    public function broadcastWith(): array
    {
        $this->trip->loadMissing(['originStation', 'destinationStation', 'route.routeStopOrders.station', 'vehicle.vehicleType']);

        return [
            'trip_id' => $this->trip->id,
            'action' => $this->action,
            'source_station_id' => $this->sourceStationId,
            'changedSeats' => $this->changedSeats,
            'trip' => [
                'id' => $this->trip->id,
                'code' => $this->trip->code,
                'display_name' => $this->trip->display_name,
                'departure_at' => $this->trip->departure_at?->toIso8601String(),
                'status' => $this->trip->status,
                'sales_control' => $this->trip->sales_control,
                'available_seats' => $this->trip->available_seats,
                'total_seats' => $this->trip->total_seats,
                'occupied_seats_count' => $this->trip->occupied_seats_count,
                'sold_tickets_count' => $this->trip->sold_tickets_count,
                'origin_station_id' => $this->trip->origin_station_id,
                'destination_station_id' => $this->trip->destination_station_id,
                'route' => [
                    'id' => $this->trip->route?->id,
                    'name' => $this->trip->route?->name,
                    'origin_station_id' => $this->trip->route?->origin_station_id,
                    'destination_station_id' => $this->trip->route?->destination_station_id,
                ],
            ],
        ];
    }
}
