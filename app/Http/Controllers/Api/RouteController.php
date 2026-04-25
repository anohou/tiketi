<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\RouteFare;

class RouteController extends Controller
{
    /**
     * Liste tous les trajets disponibles
     * GET /api/routes
     */
    public function index()
    {
        $routes = Route::with(['originStation', 'destinationStation', 'routeStopOrders.station'])
            ->where('active', true)
            ->get()
            ->map(function ($route) {
                return [
                    'id' => $route->id,
                    'name' => $route->name,
                    'origin' => [
                        'id' => $route->originStation->id,
                        'name' => $route->originStation->name,
                        'code' => $route->originStation->code,
                        'latitude' => $route->originStation->latitude,
                        'longitude' => $route->originStation->longitude,
                    ],
                    'destination' => [
                        'id' => $route->destinationStation->id,
                        'name' => $route->destinationStation->name,
                        'code' => $route->destinationStation->code,
                        'latitude' => $route->destinationStation->latitude,
                        'longitude' => $route->destinationStation->longitude,
                    ],
                    'stops' => $route->routeStopOrders->sortBy('stop_index')->map(function ($order) {
                        return [
                            'id' => $order->station->id,
                            'name' => $order->station->name,
                            'latitude' => $order->station->latitude,
                            'longitude' => $order->station->longitude,
                            'index' => $order->stop_index,
                        ];
                    })->values(),
                    'active' => $route->active,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $routes,
            'message' => 'Trajets récupérés avec succès',
        ]);
    }

    /**
     * Affiche un trajet spécifique avec tous ses détails
     * GET /api/routes/{id}
     */
    public function show(string $id)
    {
        $route = Route::with([
            'originStation',
            'destinationStation',
            'routeStopOrders.station',
        ])->findOrFail($id);

        // Load fares for stations on this route
        $stationIds = $route->routeStopOrders->pluck('station_id')->toArray();
        $fares = RouteFare::where(function ($q) use ($stationIds) {
            $q->whereIn('from_station_id', $stationIds)
                ->whereIn('to_station_id', $stationIds);
        })->get();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $route->id,
                'name' => $route->name,
                'origin' => [
                    'id' => $route->originStation->id,
                    'name' => $route->originStation->name,
                    'code' => $route->originStation->code,
                    'city' => $route->originStation->city,
                    'address' => $route->originStation->address,
                    'latitude' => $route->originStation->latitude,
                    'longitude' => $route->originStation->longitude,
                ],
                'destination' => [
                    'id' => $route->destinationStation->id,
                    'name' => $route->destinationStation->name,
                    'code' => $route->destinationStation->code,
                    'city' => $route->destinationStation->city,
                    'address' => $route->destinationStation->address,
                    'latitude' => $route->destinationStation->latitude,
                    'longitude' => $route->destinationStation->longitude,
                ],
                'stops' => $route->routeStopOrders->sortBy('stop_index')->map(function ($order) {
                    return [
                        'id' => $order->station->id,
                        'name' => $order->station->name,
                        'city' => $order->station->city,
                        'latitude' => $order->station->latitude,
                        'longitude' => $order->station->longitude,
                        'index' => $order->stop_index,
                    ];
                })->values(),
                'fares' => $fares->map(function ($fare) {
                    return [
                        'from_station_id' => $fare->from_station_id,
                        'to_station_id' => $fare->to_station_id,
                        'amount' => $fare->amount,
                    ];
                }),
                'active' => $route->active,
            ],
            'message' => 'Trajet récupéré avec succès',
        ]);
    }
}
