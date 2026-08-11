<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalSetting extends Model
{
    public const DEFAULT_OPERATIONAL_DAY_START_HOUR = 3;

    public const DEFAULT_SCHEDULED_TRIP_LOOKAHEAD_HOURS = 72;

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

    public function operationalDayStartHour(): int
    {
        return max(0, min(23, (int) data_get(
            $this->settings,
            'operational_day_start_hour',
            self::DEFAULT_OPERATIONAL_DAY_START_HOUR,
        )));
    }

    public function scheduledTripLookaheadHours(): int
    {
        return max(1, min(168, (int) data_get(
            $this->settings,
            'scheduled_trip_lookahead_hours',
            self::DEFAULT_SCHEDULED_TRIP_LOOKAHEAD_HOURS,
        )));
    }

    /**
     * Remise globale aller-retour en FCFA (0 = aucune remise). Elle est
     * soustraite du total normal (aller + retour) quel que soit le trajet.
     */
    public function roundTripDiscountAmount(): int
    {
        return max(0, (int) data_get($this->settings, 'round_trip_discount_amount', 0));
    }

    public function setRoundTripDiscountAmount(int $amount): void
    {
        $this->settings = array_merge($this->settings ?? [], [
            'round_trip_discount_amount' => max(0, $amount),
        ]);
        $this->save();
    }
}
