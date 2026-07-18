<?php

namespace App\Domain\Trips;

final class TripStatus
{
    public static function normalize(string $status): string
    {
        return match ($status) {
            'embarquement' => 'boarding',
            'parti', 'en_route' => 'departed',
            'arrive', 'arrivé' => 'arrived',
            'retardé', 'retarde' => 'delayed',
            default => $status,
        };
    }
}
