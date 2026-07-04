<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Destination extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'description',
        'region',
        'is_active',
        'settings',
    ];

    protected $appends = [
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    // GPS coordinates are stored in settings.gps to avoid schema changes.
    public function getLatitudeAttribute(): mixed
    {
        return data_get($this->settings, 'gps.latitude');
    }

    public function getLongitudeAttribute(): mixed
    {
        return data_get($this->settings, 'gps.longitude');
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

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }
}
