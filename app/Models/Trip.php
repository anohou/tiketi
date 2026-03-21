<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Trip extends Model
{
    use HasUuids;
    
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'route_id',
        'vehicle_id',
        'departure_at',
        'status',
        'booking_type',
        'sales_control',
        'origin_station_id',
        'destination_station_id'
    ];

    protected $casts = [
        'departure_at' => 'datetime',
    ];

    protected $appends = ['total_seats', 'available_seats', 'display_name'];

    /**
     * Get the display name for this trip (origin -> destination)
     */
    public function getDisplayNameAttribute()
    {
        if ($this->originStation && $this->destinationStation) {
            return $this->originStation->name . ' -> ' . $this->destinationStation->name;
        }
        // Fallback to route name if stations not set
        return $this->route?->name ?? 'Unknown';
    }

    public function getTotalSeatsAttribute()
    {
        return $this->vehicle?->vehicleType?->seat_count ?? 0;
    }

    public function getAvailableSeatsAttribute()
    {
        $total = $this->total_seats;

        // Utiliser le compteur préchargé par withCount() s'il existe,
        // sinon fallback sur une requête (évite les N+1 dans les listes)
        if (isset($this->attributes['occupied_seats'])) {
            $occupied = (int) $this->attributes['occupied_seats'];
        } elseif ($this->relationLoaded('tripSeatOccupancies')) {
            $occupied = $this->tripSeatOccupancies->count();
        } else {
            $occupied = $this->tripSeatOccupancies()->count();
        }

        return max(0, $total - $occupied);
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
        });
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
}
