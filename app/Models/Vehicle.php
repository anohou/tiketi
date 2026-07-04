<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

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
        'settings',
    ];

    protected $casts = [
        'door_positions' => 'array',
        'active' => 'boolean',
        'insurance_expiry_date' => 'date',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function trips()
    {
        return $this->hasMany(Trip::class);
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

    public function crewAssignments()
    {
        return $this->hasMany(VehicleCrewAssignment::class);
    }

    /**
     * Équipage actuellement assigné au véhicule
     */
    public function currentCrew()
    {
        return $this->hasMany(VehicleCrewAssignment::class)->whereNull('assigned_to');
    }

    /**
     * Chauffeur actuellement assigné
     */
    public function currentDriver()
    {
        return $this->hasOne(VehicleCrewAssignment::class)
            ->whereNull('assigned_to')
            ->where('role', 'driver');
    }

    /**
     * Assistant actuellement assigné
     */
    public function currentAssistant()
    {
        return $this->hasOne(VehicleCrewAssignment::class)
            ->whereNull('assigned_to')
            ->where('role', 'assistant');
    }

    /**
     * Vérifie si l'assurance du véhicule est expirée à une date donnée (ou aujourd'hui par défaut)
     */
    public function isInsuranceExpired($date = null): bool
    {
        if (!$this->insurance_expiry_date) {
            return false;
        }

        $compareDate = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return $this->insurance_expiry_date->startOfDay()->isBefore($compareDate);
    }
}
