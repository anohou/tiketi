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
            $trips = Trip::with(['route.originStation', 'route.routeStopOrders', 'vehicle.vehicleType'])
                ->withCount('tripSeatOccupancies as occupied_seats')
                ->where('departure_at', '>=', now())
                ->orderBy('departure_at')
                ->get();

            $routeFares = RouteFare::with(['fromStation.destination', 'toStation.destination'])
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
                      ->orWhereHas('routeStopOrders', function($q) use ($assignedStationIds) {
                          $q->whereIn('station_id', $assignedStationIds);
                      });
            })
            ->where('active', true)
            ->pluck('id')
            ->toArray();

            // Récupérer TOUS les voyages passant par les stations assignées (pas de filtre sales_control ici)
            // Le contrôle sales_control s'applique uniquement au moment de la vente
            // Récupérer TOUS les voyages passant par les stations assignées (pas de filtre sales_control ici)
            // Le contrôle sales_control s'applique uniquement au moment de la vente
            $trips = Trip::with([
                'route.originDestination', 
                'route.targetDestination',
                'route.originStation.destination', 
                'route.destinationStation.destination', 
                'route.routeStopOrders.station.destination', 
                'vehicle.vehicleType', 
                'originStation.destination', 
                'destinationStation.destination'
            ])
                ->withCount('tripSeatOccupancies as occupied_seats')
                ->whereIn('route_id', $assignedRouteIds)
                ->where('departure_at', '>=', now())
                ->orderBy('departure_at')
                ->get();

            // Get fares: 
            // 1. where from_station is in assigned stations (normal direction), OR
            // 2. where to_station is in assigned stations AND fare is bidirectional (reverse direction)
            $routeFares = RouteFare::with(['fromStation.destination', 'toStation.destination'])
                ->where(function($query) use ($assignedStationIds) {
                    // Normal direction: from_station is in assigned stations
                    $query->whereIn('from_station_id', $assignedStationIds)
                    // Reverse direction: to_station is in assigned stations AND is_bidirectional
                    ->orWhere(function($q) use ($assignedStationIds) {
                        $q->where('is_bidirectional', true)
                          ->whereIn('to_station_id', $assignedStationIds);
                    });
                })
                ->get();
            
            // Transform bidirectional fares: if to_station is in seller's station, swap from and to
            $routeFares = $routeFares->map(function($fare) use ($assignedStationIds) {
                // Check if this is a reverse fare (to_station is in our station, from_station is not)
                $isReversed = $fare->is_bidirectional 
                    && in_array($fare->to_station_id, $assignedStationIds)
                    && !in_array($fare->from_station_id, $assignedStationIds);
                
                // Convert to array for proper JSON serialization
                $fareArray = $fare->toArray();
                $fareArray['is_reversed'] = $isReversed;
                
                if ($isReversed) {
                    // Swap from_station and to_station for display
                    $originalFrom = $fareArray['from_station'];
                    $originalTo = $fareArray['to_station'];
                    $fareArray['from_station'] = $originalTo;
                    $fareArray['to_station'] = $originalFrom;
                    
                    // Also swap the IDs
                    $originalFromId = $fareArray['from_station_id'];
                    $originalToId = $fareArray['to_station_id'];
                    $fareArray['from_station_id'] = $originalToId;
                    $fareArray['to_station_id'] = $originalFromId;
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
            // Logic complicated by City based routes. Simplified:
            // Route must either START at assigned station OR have assigned station as Stop?
            // Usually creation is restricted to Origin.
            // Let's keep logic similar but map to Destination-based names if desired.
            $routes = \App\Models\Route::with(['originDestination', 'targetDestination'])
            ->where(function($query) use ($assignedStationIds) {
                // If route starts at one of my stations
                $query->whereIn('origin_station_id', $assignedStationIds)
                      ->orWhereIn('destination_station_id', $assignedStationIds); // Bidirectional logic mostly
            })
            ->where('active', true)
            ->orderBy('name')
            ->get();
            // ... reversed name logic if needed ...
        }

        $vehicles = \App\Models\Vehicle::with('vehicleType')->orderBy('identifier')->get(['id', 'identifier', 'seat_count', 'vehicle_type_id']);

        // Calculate real seat counts...
        $seatMapService = app(\App\Services\SeatMapService::class);
        foreach ($trips as $trip) {
            $vehicleType = $trip->vehicle?->vehicleType;
            if (!$vehicleType) {
                $trip->total_seats = 0;
                $trip->available_seats = 0;
                continue;
            }

            $seatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
                'seat_count' => $vehicleType->seat_count,
                'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
                'door_positions' => $vehicleType->door_positions ?? [],
                'last_row_seats' => $vehicleType->last_row_seats ?? 5
            ]);

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



        // Filter destinations based on available trips (for BOTH admin and sellers)
        // We only want to show destinations reachable by the currently visible trips
        $destinationIds = collect();
        
        foreach ($trips as $trip) {
            $route = $trip->route;
            if (!$route) continue;
            
            // Add origin and destination stations' destinations
            if ($route->originStation && $route->originStation->destination_id) {
                $destinationIds->push($route->originStation->destination_id);
            }
            if ($route->destinationStation && $route->destinationStation->destination_id) {
                $destinationIds->push($route->destinationStation->destination_id);
            }
            
            // Add intermediate stops' destinations
            foreach ($route->routeStopOrders ?? [] as $stopOrder) {
                if ($stopOrder->station && $stopOrder->station->destination_id) {
                    $destinationIds->push($stopOrder->station->destination_id);
                }
            }
        } // End foreach trips

        $destinations = \App\Models\Destination::whereIn('id', $destinationIds->unique())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        \Illuminate\Support\Facades\Log::info('Ticketing Debug Global', [
            'user_id' => $user->id,
            'role' => $user->role,
            'assigned_station_ids' => $assignedStationIds ?? 'N/A (Admin)',
            'trips_count' => $trips->count(),
            'destination_ids_count' => isset($destinationIds) ? $destinationIds->count() : 'All (Admin)',
            'destinations_count' => $destinations->count(),
        ]);

        return [
            'trips' => $trips,
            'routeFares' => $routeFares,
            'routes' => $routes,
            'vehicles' => $vehicles,
            'destinations' => $destinations, // Added
            'hasActiveAssignment' => $hasActiveAssignment ?? true,
            'assignedStation' => $assignedStation ?? null,
        ];
    }

    public function getSeatMap($tripId)
    {
        // Update relations to use stations
        $trip = Trip::with(['vehicle.vehicleType', 'tripSeatOccupancies.ticket.toStation', 'route.routeStopOrders'])->findOrFail($tripId);
        
        $vehicleType = $trip->vehicle->vehicleType;
        $seatCount = $trip->vehicle->seat_count;
        $occupiedSeats = $trip->tripSeatOccupancies->pluck('seat_number')->toArray();
        
        // Préparer la map des ordres d'arrêt pour ce trajet
        // Map station_id -> stop_index
        $stopOrders = $trip->route->routeStopOrders->pluck('stop_index', 'station_id');
        $totalStops = $stopOrders->count();

        // Utiliser SeatMapService pour garantir une grille 2D valide
        $seatMapService = app(\App\Services\SeatMapService::class);
        $seatMap = $seatMapService->ensureGrid($vehicleType->seat_map ?? [], [
            'seat_count' => $seatCount,
            'seat_configuration' => $vehicleType->seat_configuration ?? '2+2',
            'door_positions' => $vehicleType->door_positions ?? [],
            'last_row_seats' => $vehicleType->last_row_seats ?? 5
        ]);
        
        // Enrichir chaque siège avec les informations d'occupation
        foreach ($seatMap as &$row) {
            foreach ($row as &$seat) {
                $occupancy = $trip->tripSeatOccupancies->firstWhere('seat_number', $seat['number']);
                $seat['isOccupied'] = in_array($seat['number'], $occupiedSeats);
                
                if ($seat['isOccupied'] && $occupancy) {
                    $stationId = $occupancy->ticket->to_station_id;
                    $stopIndex = $stopOrders[$stationId] ?? 0;
                    $seat['color'] = $this->getStopColor($stopIndex, $totalStops);
                    $seat['destination_name'] = $occupancy->ticket->toStation->name ?? 'Inconnu';
                    // ... ticket info
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
