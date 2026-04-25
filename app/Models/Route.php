<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'origin_destination_id',
        'target_destination_id',
        'origin_station_id', // Optional/Deprecated?
        'destination_station_id', // Optional/Deprecated?
        'active',
    ];

    // ... booted ...

    public function originDestination()
    {
        return $this->belongsTo(Destination::class, 'origin_destination_id');
    }

    public function targetDestination()
    {
        return $this->belongsTo(Destination::class, 'target_destination_id');
    }

    public function originStation()
    {
        return $this->belongsTo(Station::class, 'origin_station_id');
    }

    public function destinationStation()
    {
        return $this->belongsTo(Station::class, 'destination_station_id');
    }

    public function routeStopOrders()
    {
        return $this->hasMany(RouteStopOrder::class)->orderBy('stop_index');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
    }
}
