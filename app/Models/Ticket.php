<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasUuids;

    public const JOURNEY_TYPE_ONE_WAY = 'one_way';

    public const JOURNEY_TYPE_ROUND_TRIP = 'round_trip';

    public const JOURNEY_TYPES = [
        self::JOURNEY_TYPE_ONE_WAY,
        self::JOURNEY_TYPE_ROUND_TRIP,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_number', 'trip_id', 'vehicle_id', 'seat_number', 'from_station_id', 'to_station_id', 'final_destination_station_id', 'transfer_station_id', 'price', 'seller_id', 'crew_member_id', 'station_id', 'status', 'boarding_group', 'qr_payload', 'passenger_name', 'passenger_phone', 'qr_code', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'settings',
        'boarded_at', 'boarded_by',
        'payment_method', 'okohi_customer_number', 'okohi_reward_id', 'okohi_transaction_id', 'gross_amount', 'discount_amount', 'amount_collected',
        'journey_type', 'public_token', 'normal_total_amount', 'round_trip_discount_amount', 'return_valid_until', 'okohi_delivery_status',
    ];

    protected $casts = [
        'qr_payload' => 'array',
        'cancelled_at' => 'datetime',
        'boarded_at' => 'datetime',
        'settings' => 'array',
        'round_trip_discount_amount' => 'integer',
        'normal_total_amount' => 'integer',
        'return_valid_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->public_token)) {
                $model->public_token = self::generatePublicToken();
            }
        });
    }

    /**
     * Jeton public opaque du billet, utilisé comme référence stable du QR
     * (format TIKETI2|{public_token}). Les anciens QR TIKETI|n°|id restent lisibles.
     */
    public static function generatePublicToken(): string
    {
        return strtoupper(bin2hex(random_bytes(16)));
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', 'issued');
    }

    public function scopeRevenueEligible(Builder $query): Builder
    {
        return $query->issued();
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

    public function finalDestinationStation()
    {
        return $this->belongsTo(Station::class, 'final_destination_station_id');
    }

    public function transferStation()
    {
        return $this->belongsTo(Station::class, 'transfer_station_id');
    }

    public function connection()
    {
        return $this->hasOne(TicketConnection::class);
    }

    public function journeys()
    {
        return $this->hasMany(TicketJourney::class);
    }

    public function outboundJourney()
    {
        return $this->hasOne(TicketJourney::class)->where('direction', TicketJourney::DIRECTION_OUTBOUND);
    }

    public function returnJourney()
    {
        return $this->hasOne(TicketJourney::class)->where('direction', TicketJourney::DIRECTION_RETURN);
    }

    public function compensations()
    {
        return $this->hasMany(TicketCompensation::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function crewMember()
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function boardedBy()
    {
        return $this->belongsTo(CrewMember::class, 'boarded_by');
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
            'final_destination_station_id' => $this->final_destination_station_id,
            'transfer_station_id' => $this->transfer_station_id,
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
        // Référence stable du billet : TIKETI2|{public_token}.
        // Les anciens QR TIKETI|ticket_number|id restent lisibles (voir ResolveScannedJourney).
        if (! empty($this->public_token)) {
            return 'TIKETI2|'.$this->public_token;
        }

        return 'TIKETI|'.$this->ticket_number.'|'.$this->id;
    }

    /**
     * Retrouve un billet à partir d'une valeur QR, ancienne ou nouvelle.
     */
    public static function resolveFromQrValue(string $qrValue): ?self
    {
        $qrValue = trim($qrValue);

        if (str_starts_with($qrValue, 'TIKETI2|')) {
            $token = substr($qrValue, strlen('TIKETI2|'));

            return self::where('public_token', $token)->first();
        }

        if (str_starts_with($qrValue, 'TIKETI|')) {
            $parts = explode('|', $qrValue);
            $ticketNumber = $parts[1] ?? null;

            return $ticketNumber ? self::where('ticket_number', $ticketNumber)->first() : null;
        }

        // Un QR peut contenir l'ID brut (compatibilité maximale).
        return self::find($qrValue);
    }

    /**
     * Valeur QR imprimée : TOUJOURS la référence stable TIKETI2|{public_token}.
     *
     * L'URL de scan Okohi n'est jamais encodée dans le QR physique : Tiketi
     * est l'unique générateur du QR et Okohi réaffiche exactement la valeur
     * reçue (point G). Les anciens QR TIKETI|n°|id restent lisibles au scan.
     */
    public function printableQrValue(?TicketSetting $settings = null): string
    {
        return $this->qrPayloadString();
    }
}
