<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DepartureSchedule extends Model
{
    use HasUuids;

    public const POLICY_REQUIRE_REAL_VEHICLE = 'require_real_vehicle';

    public const POLICY_ALLOW_PLANNED_CAPACITY = 'allow_planned_capacity';

    public const POLICIES = [
        self::POLICY_REQUIRE_REAL_VEHICLE,
        self::POLICY_ALLOW_PLANNED_CAPACITY,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'station_id',
        'route_id',
        'origin_station_id',
        'destination_station_id',
        'departure_time',
        'days_of_week',
        'valid_from',
        'valid_until',
        'timezone',
        'planned_capacity',
        'confirmed_return_quota',
        'default_vehicle_type_id',
        'vehicle_assignment_policy',
        'booking_type',
        'sales_control',
        'allows_open_connections',
        'automatic_connection_allocation',
        'active',
        'settings',
        'created_by',
    ];

    protected $casts = [
        'departure_time' => 'datetime:H:i',
        'days_of_week' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'planned_capacity' => 'integer',
        'confirmed_return_quota' => 'integer',
        'allows_open_connections' => 'boolean',
        'automatic_connection_allocation' => 'boolean',
        'active' => 'boolean',
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

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function originStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'origin_station_id');
    }

    public function destinationStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'destination_station_id');
    }

    public function defaultVehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'default_vehicle_type_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(DepartureScheduleException::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Programme actifs dont la période couvre la date donnée.
     */
    public function scopeActiveOn(Builder $query, string|\DateTimeInterface $date): Builder
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        return $query->where('active', true)
            ->where('valid_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date);
            });
    }

    /**
     * Politique de vente résolue : celle du programme, sinon le défaut compagnie.
     */
    public function resolvedPolicy(?string $companyDefault = null): string
    {
        return $this->vehicle_assignment_policy
            ?: ($companyDefault ?: self::POLICY_REQUIRE_REAL_VEHICLE);
    }

    /**
     * Le programme circule-t-il ce jour de la semaine (1 = lundi … 7 = dimanche) ?
     */
    public function runsOnDay(int $dayOfWeekIso): bool
    {
        return in_array($dayOfWeekIso, $this->days_of_week ?? [], true);
    }

    /**
     * Exception éventuelle pour une date de service donnée.
     */
    public function exceptionFor(string $serviceDate): ?DepartureScheduleException
    {
        return $this->exceptions()->whereDate('service_date', $serviceDate)->first();
    }

    /**
     * Affiche l'horaire et les jours de circulation (ex: « 08:00 · lun–sam »).
     */
    public function getDisplayLabelAttribute(): string
    {
        $time = $this->departure_time?->format('H:i') ?? '--:--';
        $days = $this->daysLabel();

        return "{$time} · {$days}";
    }

    public function daysLabel(): string
    {
        $days = $this->days_of_week ?? [];
        if (count($days) === 7) {
            return 'tous les jours';
        }

        $names = ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];
        $labels = collect($days)->sort()->map(fn (int $d) => $names[$d - 1] ?? $d)->implode('–');

        return $labels ?: 'aucun jour';
    }

    public function getRouteLabelAttribute(): string
    {
        $origin = $this->originStation?->name ?? '?';
        $destination = $this->destinationStation?->name ?? '?';

        return "{$origin} → {$destination}";
    }
}
