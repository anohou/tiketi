<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\RouteFare;
use App\Models\UserStationAssignment;
use App\Models\Route;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketingController extends Controller
{
    public function index()
    {
        $data = $this->getTicketingData();
        return Inertia::render('Seller/Ticketing', $data);
    }

    public function horizontal()
    {
        $data = $this->getTicketingData();
        return Inertia::render('Seller/TicketingHorizontal', $data);
    }

    private function getTicketingData()
    {
        $user = auth()->user();
        
        // Récupérer les voyages assignés à l'utilisateur
        if ($user->role === 'admin') {
            // Les admins voient tout
            $trips = Trip::with(['route.originStation', 'route.stops', 'route.routeStopOrders', 'vehicle.vehicleType'])
                ->withCount('tripSeatOccupancies as occupied_seats')
                ->where('departure_at', '>=', now())
                ->orderBy('departure_at')
                ->get();

            $routeFares = RouteFare::with(['fromStop.station', 'toStop.station'])
                ->get();
        } else {
            // Les vendeurs voient les voyages selon le contrôle des ventes
            $assignedStationIds = UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->pluck('station_id')
                ->toArray();
            
            $hasActiveAssignment = count($assignedStationIds) > 0;
            $assignedStation = $hasActiveAssignment 
                ? \App\Models\Station::find($assignedStationIds[0])?->name 
                : null;

            // Récupérer toutes les routes liées aux stations assignées
            $assignedRouteIds = Route::where(function($query) use ($assignedStationIds) {
                $query->whereIn('origin_station_id', $assignedStationIds)
                      ->orWhereHas('stops', function($q) use ($assignedStationIds) {
                          $q->whereIn('station_id', $assignedStationIds);
                      });
            })
            ->where('active', true)
            ->pluck('id')
            ->toArray();

            // Récupérer TOUS les voyages passant par les stations assignées (pas de filtre sales_control ici)
            // Le contrôle sales_control s'applique uniquement au moment de la vente
            $trips = Trip::with(['route.originStation', 'route.stops', 'route.routeStopOrders', 'vehicle.vehicleType', 'originStation', 'destinationStation'])
                ->withCount('tripSeatOccupancies as occupied_seats')
                ->whereIn('route_id', $assignedRouteIds)
                ->where('departure_at', '>=', now())
                ->orderBy('departure_at')
                ->get();

            // Get fares: 
            // 1. where from_stop is in assigned stations (normal direction), OR
            // 2. where to_stop is in assigned stations AND fare is bidirectional (reverse direction)
            $routeFares = RouteFare::with(['fromStop.station', 'toStop.station'])
                ->where(function($query) use ($assignedStationIds) {
                    // Normal direction: from_stop is in assigned stations
                    $query->whereHas('fromStop', function($q) use ($assignedStationIds) {
                        $q->whereIn('station_id', $assignedStationIds);
                    })
                    // Reverse direction: to_stop is in assigned stations AND is_bidirectional
                    ->orWhere(function($q) use ($assignedStationIds) {
                        $q->where('is_bidirectional', true)
                          ->whereHas('toStop', function($sq) use ($assignedStationIds) {
                              $sq->whereIn('station_id', $assignedStationIds);
                          });
                    });
                })
                ->get();
            
            // Transform bidirectional fares: if to_stop is in seller's station, swap from and to
            $routeFares = $routeFares->map(function($fare) use ($assignedStationIds) {
                $fromStationId = $fare->fromStop->station_id ?? null;
                $toStationId = $fare->toStop->station_id ?? null;
                
                // Check if this is a reverse fare (to_stop is in our station, from_stop is not)
                $isReversed = $fare->is_bidirectional 
                    && $toStationId 
                    && in_array($toStationId, $assignedStationIds)
                    && !in_array($fromStationId, $assignedStationIds);
                
                // Convert to array for proper JSON serialization
                $fareArray = $fare->toArray();
                $fareArray['is_reversed'] = $isReversed;
                
                if ($isReversed) {
                    // Swap from_stop and to_stop for display
                    $originalFromStop = $fareArray['from_stop'];
                    $originalToStop = $fareArray['to_stop'];
                    $fareArray['from_stop'] = $originalToStop;
                    $fareArray['to_stop'] = $originalFromStop;
                    
                    // Also swap the IDs
                    $originalFromId = $fareArray['from_stop_id'];
                    $originalToId = $fareArray['to_stop_id'];
                    $fareArray['from_stop_id'] = $originalToId;
                    $fareArray['to_stop_id'] = $originalFromId;
                }
                
                return $fareArray;
            })->values();
            
            $hasActiveAssignment = count($assignedStationIds) > 0;
        }

        // Récupérer toutes les routes et véhicules pour la création
        if ($user->role === 'admin') {
            $routes = \App\Models\Route::orderBy('name')->get(['id', 'name']);
        } else {
            // Un vendeur ne peut créer des voyages que pour ses routes assignées (par station)
            $routes = \App\Models\Route::with(['originStation', 'destinationStation'])
            ->where(function($query) use ($assignedStationIds) {
                $query->whereIn('origin_station_id', $assignedStationIds)
                      ->orWhereIn('destination_station_id', $assignedStationIds)
                      ->orWhereHas('stops', function($q) use ($assignedStationIds) {
                          $q->whereIn('station_id', $assignedStationIds);
                      });
            })
            ->where('active', true)
            ->orderBy('name')
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
        }

        $vehicles = \App\Models\Vehicle::with('vehicleType')->orderBy('identifier')->get(['id', 'identifier', 'seat_count', 'vehicle_type_id']);

        // Calculate real seat counts for each trip from seat_map
        foreach ($trips as $trip) {
            $seatMap = $trip->vehicle?->vehicleType?->seat_map;
            if (!is_array($seatMap)) {
                $seatMap = [];
            }
            $totalSeats = 0;
            foreach ($seatMap as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $cell) {
                    if (isset($cell['type']) && $cell['type'] === 'seat') {
                        $totalSeats++;
                    }
                }
            }
            $trip->total_seats = $totalSeats;
            $trip->available_seats = $totalSeats - ($trip->occupied_seats ?? 0);
        }

        return [
            'trips' => $trips,
            'routeFares' => $routeFares,
            'routes' => $routes,
            'vehicles' => $vehicles,
            'hasActiveAssignment' => $hasActiveAssignment ?? true,
            'assignedStation' => $assignedStation ?? null,
        ];
    }

    public function getSeatMap($tripId)
    {
        $trip = Trip::with(['vehicle.vehicleType', 'tripSeatOccupancies.ticket.toStop', 'route.routeStopOrders'])->findOrFail($tripId);
        
        $vehicleType = $trip->vehicle->vehicleType;
        $seatCount = $trip->vehicle->seat_count;
        $occupiedSeats = $trip->tripSeatOccupancies->pluck('seat_number')->toArray();
        
        // Préparer la map des ordres d'arrêt pour ce trajet
        $stopOrders = $trip->route->routeStopOrders->pluck('stop_index', 'stop_id');
        $totalStops = $stopOrders->count();

        // Utiliser la configuration du type de véhicule
        $seatMap = $vehicleType->seat_map ?? [];
        
        // Enrichir chaque siège avec les informations d'occupation
        foreach ($seatMap as &$row) {
            foreach ($row as &$seat) {
                $occupancy = $trip->tripSeatOccupancies->firstWhere('seat_number', $seat['number']);
                $seat['isOccupied'] = in_array($seat['number'], $occupiedSeats);
                
                if ($seat['isOccupied'] && $occupancy) {
                    $stopId = $occupancy->ticket->to_stop_id;
                    $stopIndex = $stopOrders[$stopId] ?? 0;
                    $seat['color'] = $this->getStopColor($stopIndex, $totalStops);
                    $seat['destination_name'] = $occupancy->ticket->toStop->name;
                    // Add Ticket Info for Supervisor Inspector
                    $seat['ticket_id'] = $occupancy->ticket->id;
                    $seat['ticket_uuid'] = $occupancy->ticket->uuid;
                    $seat['ticket_number'] = $occupancy->ticket->ticket_number;
                } else {
                    $seat['color'] = '#94A3B8';
                    $seat['destination_name'] = null;
                }
            }
        }
        
        return response()->json([
            'seat_map' => $seatMap,
            'vehicle_type' => $vehicleType,
            'total_seats' => $seatCount,
            'occupied_seats' => count($occupiedSeats),
            'available_seats' => $seatCount - count($occupiedSeats)
        ]);
    }
    
    /**
     * Génère une couleur en fonction de la distance (index)
     * Plus c'est loin, plus c'est foncé/intense
     */
    private function getStopColor($stopIndex, $totalStops)
    {
        if ($totalStops <= 1) return '#3B82F6'; // Blue-500 default

        // Normaliser l'index entre 0 et 1
        $ratio = $stopIndex / ($totalStops - 1); // 0 = début, 1 = fin
        
        // Dégradé de Bleu
        // HSL: 220 (Blue)
        // Saturation: 100%
        // Lightness: De 85% (très très clair/proche) à 30% (foncé/loin)
        
        $lightness = 85 - ($ratio * 55); // 85 -> 30
        
        return "hsl(220, 100%, {$lightness}%)";
    }
}
