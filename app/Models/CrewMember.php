<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class CrewMember extends Model
{
    use HasApiTokens, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'phone',
        'role',
        'license_number',
        'license_expiry_date',
        'pin',
        'push_token',
        'active',
        'notes',
    ];

    protected $hidden = [
        'pin',
    ];

    protected $casts = [
        'active' => 'boolean',
        'license_expiry_date' => 'date',
        'pin' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });

        static::saving(function (self $model): void {
            if ($model->isDirty('phone') && $model->phone !== null) {
                $normalized = PhoneNumber::normalize($model->phone);
                if ($normalized === null) {
                    throw ValidationException::withMessages([
                        'phone' => 'Le format du numéro de téléphone est invalide.',
                    ]);
                }
                $model->phone = $normalized;

                $duplicate = self::query()
                    ->where('phone', $model->phone)
                    ->when($model->exists, fn ($query) => $query->where($model->getKeyName(), '!=', $model->getKey()))
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'phone' => 'Ce numéro de téléphone est déjà utilisé par un autre membre d’équipage.',
                    ]);
                }
            }
        });
    }

    public function vehicleAssignments()
    {
        return $this->hasMany(VehicleCrewAssignment::class);
    }

    public function crewMessages()
    {
        return $this->hasMany(CrewMessage::class);
    }

    public function statusReports()
    {
        return $this->hasMany(CrewStatusReport::class);
    }

    /**
     * Affectation en cours (véhicule actuel)
     */
    public function currentAssignment()
    {
        return $this->hasOne(VehicleCrewAssignment::class)
            ->atDate(now())
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
        if ($this->role !== 'driver' || ! $this->license_expiry_date) {
            return false;
        }

        $compareDate = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return $this->license_expiry_date->startOfDay()->isBefore($compareDate);
    }
}
