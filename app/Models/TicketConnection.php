<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketConnection extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_id', 'transfer_station_id', 'destination_station_id', 'route_id', 'trip_id',
        'seat_number', 'status', 'planned_ready_at', 'estimated_ready_at', 'ready_at', 'assigned_at', 'assigned_by', 'assignment_mode',
        'boarded_at', 'boarded_by', 'completed_at', 'settings',
    ];

    protected $casts = [
        'ready_at' => 'datetime',
        'planned_ready_at' => 'datetime',
        'estimated_ready_at' => 'datetime',
        'assigned_at' => 'datetime',
        'boarded_at' => 'datetime',
        'completed_at' => 'datetime',
        'settings' => 'array',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function transferStation()
    {
        return $this->belongsTo(Station::class, 'transfer_station_id');
    }

    public function destinationStation()
    {
        return $this->belongsTo(Station::class, 'destination_station_id');
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function boardedBy()
    {
        return $this->belongsTo(CrewMember::class, 'boarded_by');
    }

    public function assignmentHistory()
    {
        return $this->hasMany(TicketConnectionAssignment::class);
    }

    public function hasConflict(): bool
    {
        return (bool) data_get($this->settings, 'has_conflict', false);
    }
}
