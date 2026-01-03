<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Trip;
use App\Models\Route as BusRoute;
use App\Models\UserStationAssignment;
use App\Models\Station;
use App\Models\Vehicle;
use App\Models\Ticket;

class SellerDashboardController extends Controller
{
    public function index()
    {
        $user = request()->user();
        
        if ($user->role === 'admin' || $user->role === 'supervisor') {
            $trips = Trip::with(['route', 'vehicle.vehicleType'])
                ->orderBy('departure_at','asc')
                ->limit(10)
                ->get();
            $routes = BusRoute::all();
        } else {
            // Unifier avec la logique de TicketingController: Basé sur les stations assignées
            $assignedStationIds = UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->pluck('station_id')
                ->toArray();

            $routes = BusRoute::with(['originStation', 'destinationStation'])
            ->where(function($query) use ($assignedStationIds) {
                $query->whereIn('origin_station_id', $assignedStationIds)
                      ->orWhereIn('destination_station_id', $assignedStationIds)
                      ->orWhereHas('routeStopOrders', function($q) use ($assignedStationIds) {
                          $q->whereIn('station_id', $assignedStationIds);
                      });
            })
            ->where('active', true)
            ->get()
            ->map(function($route) use ($assignedStationIds) {
                // Determine if route should be displayed in reverse direction
                // If seller's station is the destination, show reversed route name
                $isReversed = in_array($route->destination_station_id, $assignedStationIds) 
                    && !in_array($route->origin_station_id, $assignedStationIds);
                
                if ($isReversed && $route->originStation && $route->destinationStation) {
                    $route->display_name = $route->destinationStation->name . ' -> ' . $route->originStation->name;
                    $route->is_reversed = true;
                } else {
                    $route->display_name = $route->name;
                    $route->is_reversed = false;
                }
                
                return $route;
            });
            
            $routeIds = $routes->pluck('id');

            $trips = Trip::with(['route', 'vehicle.vehicleType'])
                ->whereIn('route_id', $routeIds)
                ->where('departure_at', '>=', now())
                ->orderBy('departure_at','asc')
                ->limit(10)
                ->get();
            
            $hasActiveAssignment = count($assignedStationIds) > 0;
            $assignedStation = $hasActiveAssignment 
                ? Station::find($assignedStationIds[0])?->name 
                : null;
        }
        $vehicles = Vehicle::with('vehicleType')->get();
        
        $todaySales = Ticket::where('seller_id', $user->id)
            ->whereDate('created_at', now()->today())
            ->where('status', '!=', 'cancelled')
            ->sum('price');

        return Inertia::render('Dashboards/Seller', [
            'trips' => $trips,
            'routes' => $routes,
            'vehicles' => $vehicles,
            'todaySales' => $todaySales,
            'hasActiveAssignment' => $hasActiveAssignment ?? true,
            'assignedStation' => $assignedStation ?? null,
        ]);
    }
}
