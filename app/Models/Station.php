<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Station extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name', 'code', 'city', 'address', 'phone', 'active', 'latitude', 'longitude', 'destination_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function destination()
    {
        return $this->belongsTo(Destination::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function originRoutes()
    {
        return $this->hasMany(Route::class, 'origin_station_id');
    }

    public function destinationRoutes()
    {
        return $this->hasMany(Route::class, 'destination_station_id');
    }

    public function userAssignments()
    {
        return $this->hasMany(UserStationAssignment::class);
    }

    public function assignedUsers()
    {
        return $this->belongsToMany(User::class, 'user_station_assignments');
    }
}
