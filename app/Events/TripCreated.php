<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TripCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $trip;

    /**
     * Create a new event instance.
     */
    public function __construct($trip)
    {
        $this->trip = $trip;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Broadcast to all stations involved in the trip
        $channels = [];

        // Load route stops if not loaded
        $this->trip->loadMissing('route.routeStopOrders');
        $route = $this->trip->route;

        // Origin Station
        if ($route->origin_station_id) {
            $channels[] = new PrivateChannel('station.'.$route->origin_station_id);
        }

        // Destination Station
        if ($route->destination_station_id) {
            $channels[] = new PrivateChannel('station.'.$route->destination_station_id);
        }

        // Intermediate Stops linked to stations
        foreach ($route->routeStopOrders as $stop) {
            if ($stop->station_id) {
                $channels[] = new PrivateChannel('station.'.$stop->station_id);
            }
        }

        // Broadcast to global channel for admins/executives
        $channels[] = new PrivateChannel('trips.global');

        return array_unique($channels, SORT_REGULAR);
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'TripCreated';
    }
}
