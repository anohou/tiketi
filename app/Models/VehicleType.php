<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VehicleType extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'seat_map',
        'seat_count',
        'svg_template_path',
        'seat_configuration',
        'last_row_seats',
        'door_count',
        'door_positions',
        'door_side',
        'door_width',
        'active',
    ];

    protected $casts = [
        'seat_map' => 'array',
        'door_positions' => 'array',
        'last_row_seats' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
