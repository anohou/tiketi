<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrewStatusReport extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'trip_id',
        'crew_member_id',
        'status',
        'latitude',
        'longitude',
        'note',
        'metadata',
        'reported_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'metadata' => 'array',
        'reported_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            if (empty($model->reported_at)) {
                $model->reported_at = now();
            }
        });
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function crewMember()
    {
        return $this->belongsTo(CrewMember::class);
    }
}
