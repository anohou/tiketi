<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class CrewMember extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'phone',
        'role',
        'license_number',
        'license_expiry_date',
        'active',
        'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
        'license_expiry_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function vehicleAssignments()
    {
        return $this->hasMany(VehicleCrewAssignment::class);
    }

    /**
     * Affectation en cours (véhicule actuel)
     */
    public function currentAssignment()
    {
        return $this->hasOne(VehicleCrewAssignment::class)
            ->whereNull('assigned_to')
            ->latest('assigned_from');
    }

    /**
     * Historique de toutes les affectations passées
     */
    public function pastAssignments()
    {
        return $this->hasMany(VehicleCrewAssignment::class)
            ->whereNotNull('assigned_to')
            ->orderByDesc('assigned_to');
    }

    public function isDriver(): bool
    {
        return $this->role === 'driver';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * Vérifie si le permis de conduire est expiré à une date donnée (ou aujourd'hui par défaut)
     */
    public function isLicenseExpired($date = null): bool
    {
        if ($this->role !== 'driver' || !$this->license_expiry_date) {
            return false;
        }

        $compareDate = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return $this->license_expiry_date->startOfDay()->isBefore($compareDate);
    }
}
