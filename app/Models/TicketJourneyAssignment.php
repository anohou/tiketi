<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TicketJourneyAssignment extends Model
{
    use HasUuids;

    public const MODE_AUTOMATIC = 'automatic';

    public const MODE_MANUAL = 'manual';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_journey_id',
        'previous_trip_id',
        'new_trip_id',
        'previous_seat_number',
        'new_seat_number',
        'reason',
        'mode',
        'assigned_by',
        'assigned_at',
        'settings',
    ];

    protected $casts = [
        'previous_seat_number' => 'integer',
        'new_seat_number' => 'integer',
        'assigned_at' => 'datetime',
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

    public function ticketJourney(): BelongsTo
    {
        return $this->belongsTo(TicketJourney::class);
    }

    public function previousTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'previous_trip_id');
    }

    public function newTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'new_trip_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
