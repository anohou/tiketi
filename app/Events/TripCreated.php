<?php

namespace App\Events;

use App\Models\Trip;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Trip $trip) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $this->trip->loadMissing('route.routeStopOrders');
        $route = $this->trip->route;

        // Include the trip terminals as well as the route terminals. They can
        // differ for reversed or partially-operated trips.
        $stationIds = [
            $this->trip->origin_station_id,
            $this->trip->destination_station_id,
            $route?->origin_station_id,
            $route?->destination_station_id,
        ];

        foreach ($route?->routeStopOrders ?? [] as $stop) {
            if ($stop->station_id) {
                $stationIds[] = $stop->station_id;
            }
        }

        $channelNames = collect($stationIds)
            ->filter()
            ->unique()
            ->map(fn (string $stationId) => 'station.'.$stationId)
            ->push('trips.global')
            ->push('network.global')
            ->unique()
            ->values();

        return $channelNames
            ->map(fn (string $channelName) => new PrivateChannel($channelName))
            ->all();
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'TripCreated';
    }

    public function broadcastWith(): array
    {
        $this->trip->loadMissing(['route', 'originStation', 'destinationStation', 'vehicle.vehicleType']);

        return [
            'trip' => [
                'id' => $this->trip->id,
                'code' => $this->trip->code,
                'display_name' => $this->trip->display_name,
                'departure_at' => $this->trip->departure_at?->toIso8601String(),
                'status' => $this->trip->status,
                'sales_control' => $this->trip->sales_control,
                'available_seats' => $this->trip->available_seats,
                'total_seats' => $this->trip->total_seats,
                'origin_station_id' => $this->trip->origin_station_id,
                'destination_station_id' => $this->trip->destination_station_id,
                'active_sales_station_id' => $this->trip->active_sales_station_id,
                'next_sales_station_id' => $this->trip->next_sales_station_id,
                'origin_station' => $this->trip->originStation,
                'destination_station' => $this->trip->destinationStation,
                'route' => $this->trip->route,
                'vehicle' => $this->trip->vehicle,
            ],
        ];
    }
}
