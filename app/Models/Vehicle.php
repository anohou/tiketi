<?php

namespace App\Models;

use App\Services\VehiclePoolRelocationService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Vehicle extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'identifier',
        'maker',
        'vehicle_type_id',
        'seat_count',
        'door_positions',
        'active',
        'inactive_reason',
        'insurance_expiry_date',
        'is_placeholder',
        'settings',
    ];

    protected $casts = [
        'door_positions' => 'array',
        'active' => 'boolean',
        'insurance_expiry_date' => 'date',
        'is_placeholder' => 'boolean',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });

        static::updated(function (self $model): void {
            if ($model->wasChanged('active') && ! $model->active) {
                app(VehiclePoolRelocationService::class)->returnToGeneralPool($model);
            }
        });
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
    }

    public function currentDepartedTrip()
    {
        return $this->hasOne(Trip::class)
            ->where('status', 'departed')
            ->latest('departure_at');
    }

    public function upcomingScheduledTrip()
    {
        return $this->hasOne(Trip::class)
            ->whereIn('status', ['scheduled', 'boarding'])
            ->where('departure_at', '>=', now()->subHours(2))
            ->oldest('departure_at');
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function managers()
    {
        return $this->belongsToMany(User::class, 'user_vehicle_assignments')
            ->withPivot(['active', 'settings'])
            ->withTimestamps();
    }

    public function stationAssignments()
    {
        return $this->hasMany(StationVehicleAssignment::class);
    }

    public function currentStationAssignment()
    {
        return $this->hasOne(StationVehicleAssignment::class)
            ->where('active', true)
            ->latest('updated_at');
    }

    public function crewAssignments()
    {
        return $this->hasMany(VehicleCrewAssignment::class);
    }

    /**
     * Équipage actuellement assigné au véhicule
     */
    public function currentCrew()
    {
        return $this->hasMany(VehicleCrewAssignment::class)->atDate(now());
    }

    /**
     * Véhicule technique de planification (jamais un car exploitable).
     */
    public function isPlanningPlaceholder(): bool
    {
        return (bool) $this->is_placeholder;
    }

    /**
     * Chauffeur actuellement assigné
     */
    public function currentDriver()
    {
        return $this->hasOne(VehicleCrewAssignment::class)
            ->atDate(now())
            ->where('role', 'driver');
    }

    /**
     * Assistant actuellement assigné
     */
    public function currentAssistant()
    {
        return $this->hasOne(VehicleCrewAssignment::class)
            ->atDate(now())
            ->where('role', 'assistant');
    }

    /**
     * Vérifie si l'assurance du véhicule est expirée à une date donnée (ou aujourd'hui par défaut)
     */
    public function isInsuranceExpired($date = null): bool
    {
        if (! $this->insurance_expiry_date) {
            return false;
        }

        $compareDate = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return $this->insurance_expiry_date->startOfDay()->isBefore($compareDate);
    }
}
