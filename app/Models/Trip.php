<?php

namespace App\Models;

use App\Services\TripStationProgression;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Trip extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'route_id',
        'vehicle_id',
        'departure_at',
        'planned_arrival_at',
        'actual_departed_at',
        'estimated_arrival_at',
        'status',
        'booking_type',
        'sales_control',
        'allows_open_connections',
        'automatic_connection_allocation',
        'is_replicable',
        'origin_station_id',
        'destination_station_id',
        'settings',
    ];

    protected $casts = [
        'departure_at' => 'datetime',
        'planned_arrival_at' => 'datetime',
        'actual_departed_at' => 'datetime',
        'estimated_arrival_at' => 'datetime',
        'settings' => 'array',
        'allows_open_connections' => 'boolean',
        'automatic_connection_allocation' => 'boolean',
        'is_replicable' => 'boolean',
    ];

    protected $appends = [
        'total_seats',
        'available_seats',
        'occupied_seats_count',
        'sold_tickets_count',
        'display_name',
        'active_sales_station_id',
        'next_sales_station_id',
    ];

    public function getActiveSalesStationIdAttribute(): ?string
    {
        return app(TripStationProgression::class)->activeSalesStationId($this);
    }

    public function getNextSalesStationIdAttribute(): ?string
    {
        return app(TripStationProgression::class)->nextStationId($this);
    }

    /**
     * Get the display name for this trip (origin -> destination)
     */
    public function getDisplayNameAttribute()
    {
        if ($this->originStation && $this->destinationStation) {
            return $this->originStation->name.' -> '.$this->destinationStation->name;
        }

        // Fallback to route name if stations not set
        return $this->route?->name ?? 'Unknown';
    }

    public function getTotalSeatsAttribute()
    {
        return $this->vehicle?->vehicleType?->seat_count ?? $this->vehicle?->seat_count ?? 0;
    }

    public function getAvailableSeatsAttribute()
    {
        $total = $this->total_seats;

        // Utiliser le compteur préchargé par withCount() s'il existe,
        // sinon fallback sur une requête (évite les N+1 dans les listes)
        if ($this->relationLoaded('tripSeatOccupancies')) {
            $occupied = $this->tripSeatOccupancies
                ->filter(fn ($occupancy) => ! $occupancy->ticket || $occupancy->ticket->status !== 'cancelled')
                ->pluck('seat_number')
                ->unique()
                ->count();
        } else {
            $occupied = TripSeatOccupancy::where('trip_id', $this->id)
                ->whereHas('ticket', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->distinct('seat_number')
                ->count('seat_number');
        }

        return max(0, $total - $occupied);
    }

    public function getOccupiedSeatsCountAttribute(): int
    {
        return max(0, $this->total_seats - $this->available_seats);
    }

    public function getSoldTicketsCountAttribute(): int
    {
        if ($this->relationLoaded('tickets')) {
            return $this->tickets->where('status', '!=', 'cancelled')->count();
        }

        return Ticket::where('trip_id', $this->id)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    /**
     * Vérifie si le voyage autorise les ventes depuis les stations intermédiaires
     */
    public function isSalesOpen(): bool
    {
        return $this->sales_control === 'open';
    }

    /**
     * Vérifie si le voyage est réservé à la station d'origine uniquement
     */
    public function isSalesClosed(): bool
    {
        return $this->sales_control === 'closed' || $this->sales_control === null;
    }

    /**
     * Vérifie si le voyage utilise le placement intelligent des sièges
     */
    public function isSeatAssignment(): bool
    {
        return $this->booking_type === 'seat_assignment';
    }

    /**
     * Vérifie si le voyage est en mode vrac (sans placement intelligent)
     */
    public function isBulk(): bool
    {
        return $this->booking_type === 'bulk';
    }

    /**
     * Vérifie si le voyage est en mode semi-intelligent (réutilisation des sièges)
     * Permet de vendre des tickets pour des trajets différents en réutilisant les sièges
     * qui se libèrent aux arrêts intermédiaires
     */
    public function isSemiIntelligent(): bool
    {
        return $this->booking_type === 'semi_intelligent';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            if (empty($model->code)) {
                $model->code = static::generateTripCode($model);
            }
        });

        static::created(function (self $model): void {
            $user = auth()->user();
            $changedByUserId = null;
            $changedByCrewMemberId = null;
            if ($user instanceof User) {
                $changedByUserId = $user->id;
            } elseif ($user instanceof CrewMember) {
                $changedByCrewMemberId = $user->id;
            }

            $model->statusLogs()->create([
                'status' => $model->status ?: 'scheduled',
                'changed_by_user_id' => $changedByUserId,
                'changed_by_crew_member_id' => $changedByCrewMemberId,
            ]);
        });

        static::updated(function (self $model): void {
            if ($model->wasChanged('status')) {
                $user = auth()->user();
                $changedByUserId = null;
                $changedByCrewMemberId = null;
                if ($user instanceof User) {
                    $changedByUserId = $user->id;
                } elseif ($user instanceof CrewMember) {
                    $changedByCrewMemberId = $user->id;
                }

                $model->statusLogs()->create([
                    'status' => $model->status,
                    'changed_by_user_id' => $changedByUserId,
                    'changed_by_crew_member_id' => $changedByCrewMemberId,
                ]);
            }
        });

        static::saving(function (self $model): void {
            if (empty($model->origin_station_id) || empty($model->destination_station_id)) {
                $route = $model->route;
                if ($route) {
                    if (empty($model->origin_station_id)) {
                        $model->origin_station_id = $route->origin_station_id ?? $route->routeStopOrders()->orderBy('stop_index')->first()?->station_id;
                    }
                    if (empty($model->destination_station_id)) {
                        $model->destination_station_id = $route->destination_station_id ?? $route->routeStopOrders()->orderBy('stop_index', 'desc')->first()?->station_id;
                    }
                }
            }
        });
    }

    /**
     * Order trips with upcoming departures first, then past trips, each by time.
     */
    public function scopeUpcomingFirst($query)
    {
        return $query->orderByRaw('CASE WHEN departure_at < ? THEN 1 ELSE 0 END, departure_at ASC', [now()]);
    }

    public static function generateTripCode(self $model): string
    {
        $route = $model->route;

        $originId = $model->origin_station_id;
        $destinationId = $model->destination_station_id;

        if (empty($originId) || empty($destinationId)) {
            if ($route) {
                $originId = $originId ?: ($route->origin_station_id ?? $route->routeStopOrders()->orderBy('stop_index')->first()?->station_id);
                $destinationId = $destinationId ?: ($route->destination_station_id ?? $route->routeStopOrders()->orderBy('stop_index', 'desc')->first()?->station_id);
            }
        }

        $origin = $originId ? Station::find($originId) : null;
        $destination = $destinationId ? Station::find($destinationId) : null;

        $originCode = $origin?->code ?? ($origin ? strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $origin->name), 0, 3)) : 'TRP');
        $destinationCode = $destination?->code ?? ($destination ? strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $destination->name), 0, 3)) : 'DST');

        $departureDate = $model->departure_at ? Carbon::parse($model->departure_at) : now();
        $time = $departureDate->format('Hi');

        $baseCode = sprintf('%s-%s-%s', $originCode, $destinationCode, $time);

        $dayStart = $departureDate->copy()->startOfDay();
        $dayEnd = $departureDate->copy()->endOfDay();

        $query = self::query()
            ->whereBetween('departure_at', [$dayStart, $dayEnd])
            ->where('code', 'like', $baseCode.'%');

        if ($model->exists) {
            $query->where($model->getKeyName(), '!=', $model->getKey());
        }

        $count = $query->count();

        if ($count > 0) {
            return sprintf('%s-%d', $baseCode, $count + 1);
        }

        return $baseCode;
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function originStation()
    {
        return $this->belongsTo(Station::class, 'origin_station_id');
    }

    public function destinationStation()
    {
        return $this->belongsTo(Station::class, 'destination_station_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function tripSeatOccupancies()
    {
        return $this->hasMany(TripSeatOccupancy::class);
    }

    public function assignedConnections()
    {
        return $this->hasMany(TicketConnection::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(TripStatusLog::class);
    }

    /**
     * Récupère l'équipage qui était affecté au véhicule à la date de départ du voyage.
     * Retourne une collection de VehicleCrewAssignment avec la relation crewMember chargée.
     */
    public function getCrewAttribute()
    {
        if (! $this->vehicle_id || ! $this->departure_at) {
            return collect();
        }

        return VehicleCrewAssignment::where('vehicle_id', $this->vehicle_id)
            ->atDate($this->departure_at)
            ->with('crewMember')
            ->get();
    }

    /**
     * Récupère le chauffeur du voyage (basé sur l'affectation véhicule à la date de départ)
     */
    public function getDriverAttribute()
    {
        return $this->crew->firstWhere('role', 'driver')?->crewMember;
    }

    /**
     * Récupère l'assistant du voyage (basé sur l'affectation véhicule à la date de départ)
     */
    public function getAssistantAttribute()
    {
        return $this->crew->firstWhere('role', 'assistant')?->crewMember;
    }
}
