<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class StationVehicleAssignment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'station_id',
        'vehicle_id',
        'valid_from',
        'valid_until',
        'active',
        'notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'active' => 'boolean',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeActiveOn(Builder $query, Carbon|string|null $date = null): Builder
    {
        $date = Carbon::parse($date ?? now())->toDateString();

        return $query
            ->where('active', true)
            ->where(fn (Builder $period) => $period
                ->whereNull('valid_from')
                ->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $period) => $period
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', $date));
    }

    public function getIsPermanentAttribute(): bool
    {
        return $this->valid_from === null && $this->valid_until === null;
    }
}
