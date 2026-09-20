<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RouteFare;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DepartureBoardPublicController extends Controller
{
    /**
     * GET /api/departures/today?tenant_id=xxx
     *
     * Retourne tous les voyages du jour (heure locale), non annulés.
     * Cache court (30 s) pour le tableau des départs temps réel.
     */
    public function today(Request $request)
    {
        $this->initTenantIfNeeded($request);

        $today = Carbon::today();

        $trips = Trip::with([
            'originStation',
            'destinationStation',
            'vehicle.vehicleType',
        ])
            ->whereDate('departure_at', $today)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('departure_at')
            ->get()
            ->map(fn (Trip $trip) => $this->formatTrip($trip));

        return response()->json([
            'success' => true,
            'date' => $today->toDateString(),
            'data' => $trips,
        ])->setPublic()->setMaxAge(30);
    }

    /**
     * GET /api/departures?date=YYYY-MM-DD&tenant_id=xxx
     *
     * Retourne les voyages pour une date donnée (défaut : aujourd'hui).
     */
    public function byDate(Request $request)
    {
        $this->initTenantIfNeeded($request);

        $dateParam = $request->query('date');
        $date = $dateParam ? Carbon::createFromFormat('Y-m-d', $dateParam)->startOfDay() : Carbon::today();

        $trips = Trip::with([
            'originStation',
            'destinationStation',
            'vehicle.vehicleType',
        ])
            ->whereDate('departure_at', $date)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('departure_at')
            ->get()
            ->map(fn (Trip $trip) => $this->formatTrip($trip));

        return response()->json([
            'success' => true,
            'date' => $date->toDateString(),
            'data' => $trips,
        ])->setPublic()->setMaxAge(30);
    }

    /**
     * Initialise le tenant manuellement si la tenancy n'est pas déjà active.
     * Nécessaire quand l'appel arrive via IP (ex: depuis Okohi mobile).
     */
    private function initTenantIfNeeded(Request $request): void
    {
        if (tenancy()->initialized) {
            return;
        }

        $tenantId = $request->query('tenant_id');
        if (! $tenantId) {
            abort(400, 'tenant_id is required when calling via IP address.');
        }

        try {
            tenancy()->initialize($tenantId);
        } catch (\Throwable $e) {
            abort(404, 'Tenant not found: '.$e->getMessage());
        }
    }

    private function formatTrip(Trip $trip): array
    {
        $origin = $trip->originStation;
        $destination = $trip->destinationStation;

        return [
            'id' => $trip->id,
            'code' => $trip->code,
            'departure_at' => $trip->departure_at?->toIso8601String(),
            'planned_arrival_at' => $trip->planned_arrival_at?->toIso8601String(),
            'status' => $trip->status,
            'sales_control' => $trip->sales_control,
            'booking_type' => $trip->booking_type,
            'route' => [
                'id' => $trip->route_id,
                'name' => $trip->display_name ?? '',
                'origin' => $origin ? [
                    'id' => $origin->id,
                    'name' => $origin->name,
                    'city' => $origin->city ?? '',
                ] : null,
                'destination' => $destination ? [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'city' => $destination->city ?? '',
                ] : null,
            ],
            'vehicle' => $trip->vehicle?->vehicleType ? [
                'type' => $trip->vehicle->vehicleType->name,
                'capacity' => $trip->total_seats,
            ] : null,
            'price' => ($trip->origin_station_id && $trip->destination_station_id)
                ? RouteFare::getFare($trip->origin_station_id, $trip->destination_station_id)
                : null,
        ];
    }
}
