<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VehicleCrewAssignment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'vehicle_id',
        'crew_member_id',
        'role',
        'assigned_from',
        'assigned_to',
        'notes',
    ];

    protected $casts = [
        'assigned_from' => 'datetime',
        'assigned_to' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function crewMember()
    {
        return $this->belongsTo(CrewMember::class);
    }

    /**
     * Scope : affectations actives (en cours)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('assigned_to');
    }

    /**
     * Scope : affectation valide à une date donnée
     * Permet de retrouver qui était assigné au véhicule à un moment précis
     */
    public function scopeAtDate($query, $date)
    {
        return $query->where('assigned_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '>=', $date));
    }
}
