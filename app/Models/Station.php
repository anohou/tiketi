<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class Station extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name', 'code', 'city', 'address', 'phone', 'active', 'can_sell_tickets', 'destination_id', 'settings',
    ];

    protected $appends = [
        'latitude',
        'longitude',
        'can_sell_tickets',
    ];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
    ];

    // GPS coordinates are stored in settings.gps to avoid schema changes.
    public function getLatitudeAttribute(): mixed
    {
        return data_get($this->settings, 'gps.latitude', $this->attributes['latitude'] ?? null);
    }

    public function getLongitudeAttribute(): mixed
    {
        return data_get($this->settings, 'gps.longitude', $this->attributes['longitude'] ?? null);
    }

    public function getCanSellTicketsAttribute(): bool
    {
        return (bool) data_get($this->settings, 'can_sell_tickets', true);
    }

    public function setCanSellTicketsAttribute(mixed $value): void
    {
        $settings = $this->settings ?? [];
        Arr::set($settings, 'can_sell_tickets', filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value);
        $this->attributes['settings'] = json_encode($settings);
    }

    public function setLatitudeAttribute(mixed $value): void
    {
        $settings = $this->settings ?? [];
        Arr::set($settings, 'gps.latitude', $value !== '' ? $value : null);
        $this->attributes['settings'] = json_encode($settings);
    }

    public function setLongitudeAttribute(mixed $value): void
    {
        $settings = $this->settings ?? [];
        Arr::set($settings, 'gps.longitude', $value !== '' ? $value : null);
        $this->attributes['settings'] = json_encode($settings);
    }

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

    public function routeStopOrders()
    {
        return $this->hasMany(RouteStopOrder::class);
    }
}
