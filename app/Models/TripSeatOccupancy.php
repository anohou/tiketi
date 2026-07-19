<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TripSeatOccupancy extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'trip_id',
        'seat_number',
        'ticket_id',
        'from_station_id',
        'to_station_id',
        'okohi_reward_request_id',
        'expires_at',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function fromStation()
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation()
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    public function okohiRewardRequest()
    {
        return $this->belongsTo(OkohiRewardRequest::class, 'okohi_reward_request_id');
    }
}
