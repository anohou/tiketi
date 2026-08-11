<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TicketJourney extends Model
{
    use HasUuids;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_RETURN = 'return';

    public const SELECTION_FIXED_TRIP = 'fixed_trip';

    public const SELECTION_FIXED_SCHEDULE = 'fixed_schedule';

    public const SELECTION_DATE_FLEXIBLE = 'date_flexible';

    public const SELECTION_OPEN = 'open';

    public const SEAT_UNASSIGNED = 'unassigned';

    public const SEAT_CONFIRMED = 'confirmed';

    public const SEAT_REASSIGNED = 'reassigned';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_TRIP = 'awaiting_trip';

    public const STATUS_READY = 'ready';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_BOARDED = 'boarded';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_MISSED = 'missed';

    public const DIRECTIONS = [
        self::DIRECTION_OUTBOUND,
        self::DIRECTION_RETURN,
    ];

    public const SELECTION_MODES = [
        self::SELECTION_FIXED_TRIP,
        self::SELECTION_FIXED_SCHEDULE,
        self::SELECTION_DATE_FLEXIBLE,
        self::SELECTION_OPEN,
    ];

    public const SEAT_STATUSES = [
        self::SEAT_UNASSIGNED,
        self::SEAT_CONFIRMED,
        self::SEAT_REASSIGNED,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_TRIP,
        self::STATUS_READY,
        self::STATUS_ASSIGNED,
        self::STATUS_BOARDED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
        self::STATUS_MISSED,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_id',
        'direction',
        'from_station_id',
        'to_station_id',
        'selection_mode',
        'departure_schedule_id',
        'desired_travel_date',
        'desired_departure_time',
        'trip_id',
        'vehicle_id',
        'seat_number',
        'seat_assignment_status',
        'status',
        'valid_from',
        'valid_until',
        'assigned_at',
        'assigned_by',
        'boarded_at',
        'boarded_by',
        'completed_at',
        'settings',
    ];

    protected $casts = [
        'desired_travel_date' => 'date',
        'desired_departure_time' => 'datetime:H:i',
        'seat_number' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'assigned_at' => 'datetime',
        'boarded_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function departureSchedule(): BelongsTo
    {
        return $this->belongsTo(DepartureSchedule::class);
    }

    public function fromStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TicketJourneyAssignment::class);
    }

    public function isReturn(): bool
    {
        return $this->direction === self::DIRECTION_RETURN;
    }

    public function isOutbound(): bool
    {
        return $this->direction === self::DIRECTION_OUTBOUND;
    }

    /**
     * Le droit possède une place confirmée ou réaffectée.
     */
    public function hasConfirmedSeat(): bool
    {
        return in_array($this->seat_assignment_status, [self::SEAT_CONFIRMED, self::SEAT_REASSIGNED], true)
            && $this->seat_number !== null;
    }

    /**
     * Le droit est-il mobilisable (affecté à un voyage précis) ?
     */
    public function isAssignedToTrip(): bool
    {
        return $this->trip_id !== null;
    }
}
