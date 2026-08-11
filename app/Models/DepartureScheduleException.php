<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DepartureScheduleException extends Model
{
    use HasUuids;

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_TIME_CHANGED = 'time_changed';

    public const TYPE_SUSPENDED = 'suspended';

    public const TYPE_CAPACITY_CHANGED = 'capacity_changed';

    public const TYPES = [
        self::TYPE_CANCELLED,
        self::TYPE_TIME_CHANGED,
        self::TYPE_SUSPENDED,
        self::TYPE_CAPACITY_CHANGED,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'departure_schedule_id',
        'service_date',
        'type',
        'replacement_time',
        'replacement_capacity',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'service_date' => 'date',
        'replacement_time' => 'datetime:H:i',
        'replacement_capacity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function departureSchedule(): BelongsTo
    {
        return $this->belongsTo(DepartureSchedule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Une exception suspend/annule le créneau pour cette date.
     */
    public function preventsService(): bool
    {
        return in_array($this->type, [self::TYPE_CANCELLED, self::TYPE_SUSPENDED], true);
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_CANCELLED => 'Annulé',
            self::TYPE_TIME_CHANGED => 'Horaire modifié',
            self::TYPE_SUSPENDED => 'Suspendu',
            self::TYPE_CAPACITY_CHANGED => 'Capacité modifiée',
            default => $this->type,
        };
    }
}
