<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_number', 'trip_id', 'vehicle_id', 'seat_number', 'from_station_id', 'to_station_id', 'price', 'seller_id', 'station_id', 'status', 'boarding_group', 'qr_payload', 'passenger_name', 'passenger_phone', 'qr_code', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'settings',
    ];

    protected $casts = [
        'qr_payload' => 'array',
        'cancelled_at' => 'datetime',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function fromStation()
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation()
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function qrPayloadData(): array
    {
        return $this->qr_payload ?: [
            'ticket_id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'trip_id' => $this->trip_id,
            'from_station_id' => $this->from_station_id,
            'to_station_id' => $this->to_station_id,
            'from_stop' => $this->fromStation?->name,
            'to_stop' => $this->toStation?->name,
            'seat_number' => $this->seat_number,
            'boarding_group' => $this->boarding_group,
            'passenger_name' => $this->passenger_name,
            'price' => $this->price,
            'issued_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function qrPayloadString(): string
    {
        return implode('|', array_filter([
            'TIKETI',
            $this->ticket_number,
            $this->id,
        ]));
    }

    public function printableQrValue(?TicketSetting $settings = null): string
    {
        if ($settings?->hasOkohiIntegration()) {
            return $settings->okohiScanUrl($this) ?? $this->qrPayloadString();
        }

        return $this->qrPayloadString();
    }
}
