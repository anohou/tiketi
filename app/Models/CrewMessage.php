<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CrewMessage extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'trip_id',
        'crew_member_id',
        'type',
        'body',
        'audio_path',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
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
