<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteFare extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'from_station_id',
        'to_station_id',
        'amount',
        'is_bidirectional',
    ];

    protected $casts = [
        'is_bidirectional' => 'boolean',
    ];

    public function fromStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    /**
     * Get the fare amount for a specific journey.
     * Checks both directions if fare is bidirectional.
     */
    public static function getFare(string $fromStationId, string $toStationId): ?int
    {
        // First try direct match
        $fare = self::where('from_station_id', $fromStationId)
            ->where('to_station_id', $toStationId)
            ->first();

        if ($fare) {
            return $fare->amount;
        }

        // Try reverse direction if bidirectional
        $reverseFare = self::where('from_station_id', $toStationId)
            ->where('to_station_id', $fromStationId)
            ->where('is_bidirectional', true)
            ->first();

        return $reverseFare?->amount;
    }

    /**
     * Find fare for a journey, considering bidirectional fares.
     * Returns the fare object with direction info.
     */
    public static function findFare(string $fromStationId, string $toStationId): ?array
    {
        // First try direct match
        $fare = self::where('from_station_id', $fromStationId)
            ->where('to_station_id', $toStationId)
            ->first();

        if ($fare) {
            return [
                'fare' => $fare,
                'amount' => $fare->amount,
                'is_reversed' => false,
            ];
        }

        // Try reverse direction if bidirectional
        $reverseFare = self::where('from_station_id', $toStationId)
            ->where('to_station_id', $fromStationId)
            ->where('is_bidirectional', true)
            ->first();

        if ($reverseFare) {
            return [
                'fare' => $reverseFare,
                'amount' => $reverseFare->amount,
                'is_reversed' => true,
            ];
        }

        return null;
    }
}
