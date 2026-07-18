<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalSetting extends Model
{
    protected $fillable = [
        'automatic_connection_allocation',
        'connection_transfer_buffer_minutes',
        'settings',
    ];

    protected $casts = [
        'automatic_connection_allocation' => 'boolean',
        'connection_transfer_buffer_minutes' => 'integer',
        'settings' => 'array',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create();
    }
}
