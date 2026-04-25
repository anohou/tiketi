<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUuids, Notifiable;

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
        return $this->hasMany(\App\Models\UserRouteAssignment::class);
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
}
