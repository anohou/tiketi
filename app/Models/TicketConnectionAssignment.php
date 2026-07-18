<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TicketConnectionAssignment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_connection_id', 'from_trip_id', 'to_trip_id', 'from_seat_number',
        'to_seat_number', 'action', 'reason', 'performed_by', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function connection()
    {
        return $this->belongsTo(TicketConnection::class, 'ticket_connection_id');
    }
}
