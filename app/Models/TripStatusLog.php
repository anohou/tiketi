<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TripStatusLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'trip_id',
        'status',
        'changed_by_user_id',
        'changed_by_crew_member_id',
        'note',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function changedByCrewMember()
    {
        return $this->belongsTo(CrewMember::class, 'changed_by_crew_member_id');
    }
}
