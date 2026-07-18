<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketCompensation extends Model
{
    use HasUuids;

    protected $table = 'ticket_compensations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'approved_at' => 'datetime', 'executed_at' => 'datetime', 'settings' => 'array'];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function replacementTrip()
    {
        return $this->belongsTo(Trip::class, 'replacement_trip_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
