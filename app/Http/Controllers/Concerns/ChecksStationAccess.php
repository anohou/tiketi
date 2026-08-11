<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Station;
use App\Models\Trip;

/**
 * Vérification d'accès des vendeurs aux gares et aux voyages (point E).
 *
 * Un vendeur ne peut consulter ou muter que :
 * - ses gares actives ;
 * - les voyages partant d'une gare accessible ;
 * - les véhicules du tenant (jamais un simple UUID reçu).
 */
trait ChecksStationAccess
{
    private function assertUserCanAccessStation(Station $station): void
    {
        $user = auth()->user();

        if (! $user || $user->role === 'admin' || $user->role === 'supervisor') {
            return;
        }

        $assignedStationIds = $user->getActiveStationIds();

        if (! in_array($station->id, $assignedStationIds, true)) {
            abort(403, 'Vous n’êtes pas autorisé à exploiter cette gare.');
        }
    }

    private function assertUserCanAccessTrip(Trip $trip): void
    {
        $user = auth()->user();

        if (! $user || $user->role === 'admin' || $user->role === 'supervisor') {
            return;
        }

        $assignedStationIds = $user->getActiveStationIds();

        if (! in_array($trip->origin_station_id, $assignedStationIds, true)) {
            abort(403, 'Vous n’êtes pas autorisé à agir sur ce voyage.');
        }
    }
}
