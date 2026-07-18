<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'telephone',
        'password',
        'role',
        'active',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function routeAssignments()
    {
        return $this->hasMany(UserRouteAssignment::class);
    }

    public function routes()
    {
        return $this->belongsToMany(Route::class, 'user_route_assignments');
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function soldTickets()
    {
        return $this->hasMany(Ticket::class, 'seller_id');
    }

    public function stationAssignments()
    {
        return $this->hasMany(UserStationAssignment::class);
    }

    public function assignedStations()
    {
        return $this->belongsToMany(Station::class, 'user_station_assignments');
    }

    public function vehicleAssignments()
    {
        return $this->hasMany(UserVehicleAssignment::class);
    }

    public function assignedVehicles()
    {
        return $this->belongsToMany(Vehicle::class, 'user_vehicle_assignments');
    }

    public function getActiveStationIds(): array
    {
        return $this->stationAssignments()
            ->where('active', true)
            ->pluck('station_id')
            ->toArray();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function accessibleRoutesQuery()
    {
        if ($this->isAdmin()) {
            return Route::where('active', true);
        }

        $stationIds = $this->getActiveStationIds();
        if (empty($stationIds)) {
            return Route::whereRaw('1 = 0');
        }

        return Route::where('active', true)
            ->where(function ($query) use ($stationIds) {
                foreach ($stationIds as $stationId) {
                    $query->orWhere(function ($q) use ($stationId) {
                        $hasRouteAssignments = $this->routeAssignments()
                            ->where('station_id', $stationId)
                            ->where('active', true)
                            ->exists();

                        if ($hasRouteAssignments) {
                            $assignedRouteIds = $this->routeAssignments()
                                ->where('station_id', $stationId)
                                ->where('active', true)
                                ->pluck('route_id')
                                ->toArray();

                            $q->whereIn('id', $assignedRouteIds);
                        } else {
                            $q->where(function ($sub) use ($stationId) {
                                $sub->where('origin_station_id', $stationId)
                                    ->orWhere('destination_station_id', $stationId)
                                    ->orWhereHas('routeStopOrders', fn ($sq) => $sq->where('station_id', $stationId));
                            });
                        }
                    });
                }
            });
    }
}
