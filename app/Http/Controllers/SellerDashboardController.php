<?php

namespace App\Http\Controllers;

use App\Domain\Trips\CrewTripVisibility;
use App\Models\Route as BusRoute;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SellerDashboardController extends Controller
{
    public function index()
    {
        $user = request()->user();

        if ($user->role === 'admin' || $user->role === 'supervisor') {
            $trips = Trip::with(['route', 'vehicle.vehicleType'])
                ->upcomingFirst()
                ->limit(10)
                ->get();
            $routes = BusRoute::with(['originStation', 'destinationStation', 'routeStopOrders.station'])->get();
            if ($user->role === 'supervisor') {
                $routes = $user->accessibleRoutesQuery()
                    ->with(['originStation', 'destinationStation', 'routeStopOrders.station'])
                    ->orderBy('name')
                    ->get();
            }
        } else {
            // Unifier avec la logique de TicketingController: Basé sur les stations assignées
            $assignedStationIds = $user->getActiveStationIds();

            $routes = $user->accessibleRoutesQuery()
                ->with(['originStation', 'destinationStation', 'routeStopOrders.station'])
                ->get()
                ->map(function ($route) use ($assignedStationIds) {
                    // Determine if route should be displayed in reverse direction
                    // If seller's station is the destination, show reversed route name
                    $isReversed = in_array($route->destination_station_id, $assignedStationIds)
                        && ! in_array($route->origin_station_id, $assignedStationIds);

                    if ($isReversed && $route->originStation && $route->destinationStation) {
                        $route->display_name = $route->destinationStation->name.' -> '.$route->originStation->name;
                        $route->is_reversed = true;
                    } else {
                        $route->display_name = $route->name;
                        $route->is_reversed = false;
                    }

                    return $route;
                });

            $routeIds = $routes->pluck('id');

            [$windowStart, $windowEnd] = app(CrewTripVisibility::class)->operationalWindow();
            $activeStart = now()->subHours((int) config('transport.operations.active_trip_lookback_hours', 48));

            $trips = Trip::with(['route', 'vehicle.vehicleType'])
                ->whereIn('route_id', $routeIds)
                ->where(function ($query) use ($windowStart, $windowEnd, $activeStart) {
                    $query->where(function ($scheduled) use ($windowStart, $windowEnd) {
                        // Route visibility must not depend on simultaneous sales.
                        $scheduled->where('departure_at', '>=', $windowStart)
                            ->where('departure_at', '<', $windowEnd);
                    })->orWhere(function ($active) use ($activeStart, $windowEnd) {
                        $active->whereIn('status', ['boarding', 'delayed', 'departed'])
                            ->where('departure_at', '>=', $activeStart)
                            ->where('departure_at', '<', $windowEnd);
                    });
                })
                ->upcomingFirst()
                ->limit(10)
                ->get();

            $hasActiveAssignment = count($assignedStationIds) > 0;
            $assignedStation = $hasActiveAssignment
                ? Station::find($assignedStationIds[0])?->name
                : null;
        }
        $vehicles = Vehicle::with('vehicleType')->get();
        $assignedStationIds = $user->getActiveStationIds();
        $canSelectTripOrigin = in_array($user->role, ['admin', 'supervisor'], true);
        $originStations = ($user->role === 'admin'
            ? Station::where('active', true)
            : Station::where('active', true)->whereIn('id', $assignedStationIds))
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'code']);
        $assignedStation = $assignedStation
            ?? ($assignedStationIds ? Station::find($assignedStationIds[0])?->name : null);

        $todaySales = Ticket::where('seller_id', $user->id)
            ->whereDate('created_at', now()->today())
            ->where('status', 'issued')
            ->sum(DB::raw('COALESCE(amount_collected, price)'));

        return Inertia::render('Dashboards/Seller', [
            'trips' => $trips,
            'routes' => $routes,
            'vehicles' => $vehicles,
            'todaySales' => $todaySales,
            'hasActiveAssignment' => $hasActiveAssignment ?? true,
            'assignedStation' => $assignedStation ?? null,
            'canSelectTripOrigin' => $canSelectTripOrigin,
            'originStations' => $originStations,
        ]);
    }
}
