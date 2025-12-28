<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\Stop;
use App\Models\RouteStopOrder;
use App\Models\Ticket;
use Illuminate\Support\Collection;

class OptimisationService
{
    /**
     * Obtient les suggestions de sièges optimaux pour un voyage et une destination
     * 
     * @param string $tripId ID du voyage
     * @param string $destinationStopId ID de l'arrêt de destination
     * @param int $maxSuggestions Nombre maximum de suggestions (défaut: 5)
     * @return array Tableau de suggestions avec seat_number, score et reason
     */
    public function getSuggestedSeats(string $tripId, string $destinationStopId, int $maxSuggestions = 5, ?string $boardingStopId = null): array
    {
        $trip = Trip::with(['vehicle.vehicleType', 'route.routeStopOrders'])->findOrFail($tripId);
        
        // Si le voyage est en mode "bulk", retourner un tableau vide (pas de suggestions)
        if ($trip->isBulk()) {
            return [];
        }

        // DETECT IF TRIP IS REVERSED compared to route's default direction
        // If trip's origin_station_id != route's origin_station_id, the trip is reversed
        $isReversedTrip = $trip->origin_station_id && 
                          $trip->route && 
                          $trip->origin_station_id !== $trip->route->origin_station_id;

        // Récupérer les sièges déjà occupés avec leurs destinations et origines
        $occupiedSeatsData = Ticket::where('trip_id', $tripId)
            ->where('status', '!=', 'cancelled')
            ->with(['toStop', 'fromStop'])
            ->get()
            ->keyBy('seat_number');

        // Récupérer la configuration du véhicule
        $vehicleType = $trip->vehicle->vehicleType;
        $totalSeats = $vehicleType->seat_count;
        $doorPositions = $vehicleType->door_positions ?? [0];
        
        // Parse the seat map to get accurate seat types and neighbors
        $seatMapInfo = $this->parseSeatMap($vehicleType->seat_map ?? []);

        // Calculer l'index de l'arrêt de destination (considering trip direction)
        $destinationIndex = $this->getStopIndex($trip->route_id, $destinationStopId, $isReversedTrip);
        
        // Get boarding stop index (for semi-intelligent mode)
        $boardingIndex = $boardingStopId ? $this->getStopIndex($trip->route_id, $boardingStopId, $isReversedTrip) : 0;
        
        // Get total stops on route
        $totalStops = RouteStopOrder::where('route_id', $trip->route_id)->count();
        
        // Calculate trip distance ratio (0 = first stop, 1 = last stop)
        $tripDistanceRatio = $totalStops > 1 ? $destinationIndex / ($totalStops - 1) : 0;
        
        // Define tronçons (route segments) based on total stops
        // Short: < 40%, Medium: 40-70%, Long: >= 70%
        $troncon = 'long'; // default
        if ($tripDistanceRatio < 0.40) {
            $troncon = 'short';
        } elseif ($tripDistanceRatio < 0.70) {
            $troncon = 'medium';
        }
        
        // DYNAMIC ZONES LOGIC
        // N = Number of destinations (stops excluding departure)
        $numZones = max(1, $totalStops - 1);
        
        // Determine available seats based on booking type
        $availableSeats = [];
        
        if ($trip->isSemiIntelligent()) {
            // SEMI-INTELLIGENT MODE: Seats can be reused
            for ($seatNumber = 1; $seatNumber <= $totalSeats; $seatNumber++) {
                if (!$occupiedSeatsData->has($seatNumber)) {
                    $availableSeats[] = $seatNumber;
                } else {
                    $currentOccupant = $occupiedSeatsData[$seatNumber];
                    $occupantDestIndex = $this->getStopIndex($trip->route_id, $currentOccupant->to_stop_id, $isReversedTrip);
                    if ($occupantDestIndex < $boardingIndex) {
                        $availableSeats[] = $seatNumber;
                    }
                }
            }
        } else {
            // INTELLIGENT MODE: Only truly empty seats
            for ($seatNumber = 1; $seatNumber <= $totalSeats; $seatNumber++) {
                if (!$occupiedSeatsData->has($seatNumber)) {
                    $availableSeats[] = $seatNumber;
                }
            }
        }
        
        // Get Preference Order for Zones based on Trip Type
        $zonePreferences = $this->getZonePreferences($numZones, $troncon);
        
        // Calculate scores for available seats
        $seatScores = [];
        foreach ($availableSeats as $seatNumber) {
            $score = $this->calculateDynamicZoneScore(
                $seatNumber,
                $troncon,
                $destinationIndex,
                $numZones,
                $zonePreferences,
                $totalSeats,
                $doorPositions,
                $seatMapInfo,
                $occupiedSeatsData,
                $trip->route_id,
                $isReversedTrip
            );

            $seatScores[] = [
                'seat_number' => $seatNumber,
                'score' => $score['score'],
                'reason' => $score['reason']
            ];
        }

        // Trier par score décroissant et prendre les N meilleurs
        usort($seatScores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($seatScores, 0, $maxSuggestions);
    }

    /**
     * Parse the seat map to determine seat types, neighbors and coordinates
     */
    private function parseSeatMap(array $seatMap): array
    {
        $info = [
            'seats' => [],
            'doors' => []
        ];
        
        foreach ($seatMap as $rowIndex => $row) {
            $rowLength = count($row);
            
            foreach ($row as $colIndex => $cell) {
                if (!isset($cell['type'])) {
                    continue;
                }

                if ($cell['type'] === 'door') {
                    $info['doors'][] = [
                        'row' => $rowIndex,
                        'col' => $colIndex
                    ];
                    continue;
                }

                if ($cell['type'] !== 'seat') {
                    continue;
                }
                
                $seatNumber = (int)$cell['number'];
                $type = 'middle'; // Default
                $adjacentAisleSeats = [];
                
                // Check Left Neighbor
                $leftIsWall = ($colIndex === 0);
                $leftIsAisle = false;
                if (!$leftIsWall) {
                    $leftCell = $row[$colIndex - 1];
                    if ($leftCell['type'] === 'aisle' || $leftCell['type'] === 'door' || $leftCell['type'] === 'empty') {
                        $leftIsAisle = true;
                    }
                }
                
                // Check Right Neighbor
                $rightIsWall = ($colIndex === $rowLength - 1);
                $rightIsAisle = false;
                if (!$rightIsWall) {
                    $rightCell = $row[$colIndex + 1];
                    if ($rightCell['type'] === 'aisle' || $rightCell['type'] === 'door' || $rightCell['type'] === 'empty') {
                        $rightIsAisle = true;
                    }
                }
                
                // Determine Type
                if ($leftIsWall || $rightIsWall) {
                    $type = 'window';
                } elseif ($leftIsAisle || $rightIsAisle) {
                    $type = 'aisle';
                }
                
                // Find adjacent aisle seats (for blocking check)
                if ($type === 'window' || $type === 'middle') {
                    // Search towards the aisle in the same row
                    // Left search
                    for ($i = $colIndex - 1; $i >= 0; $i--) {
                        if ($row[$i]['type'] === 'aisle') break;
                        if ($row[$i]['type'] === 'seat') {
                            $adjacentAisleSeats[] = (int)$row[$i]['number'];
                        }
                    }
                    // Right search
                    for ($i = $colIndex + 1; $i < $rowLength; $i++) {
                        if ($row[$i]['type'] === 'aisle') break;
                        if ($row[$i]['type'] === 'seat') {
                            $adjacentAisleSeats[] = (int)$row[$i]['number'];
                        }
                    }
                }
                
                $info['seats'][$seatNumber] = [
                    'type' => $type,
                    'adjacent_aisle_seats' => array_unique($adjacentAisleSeats),
                    'row' => $rowIndex,
                    'col' => $colIndex
                ];
            }
        }
        
        // --- Calculate Global Proximity Ranking ---
        $seatDistances = [];
        foreach ($info['seats'] as $seatNumber => $s) {
            $minDist = PHP_INT_MAX;
            foreach ($info['doors'] as $door) {
                $dist = abs($s['row'] - $door['row']) + abs($s['col'] - $door['col']);
                if ($dist < $minDist) {
                    $minDist = $dist;
                }
            }
            $seatDistances[$seatNumber] = $minDist;
        }
        
        // Sort seats by proximity (ascending)
        // If distance is equal, prefer lower seat numbers (usually front)
        uksort($seatDistances, function($a, $b) use ($seatDistances) {
            if ($seatDistances[$a] !== $seatDistances[$b]) {
                return $seatDistances[$a] <=> $seatDistances[$b];
            }
            return $a <=> $b; // Prefer lower seat number if distance is same
        });
        
        $rank = 0;
        $info['proximity_ranking'] = [];
        foreach ($seatDistances as $seatNumber => $dist) {
            $rank++;
            $info['seats'][$seatNumber]['proximity_rank'] = $rank;
            $info['proximity_ranking'][] = $seatNumber; // Order based on proximity
        }
        
        return $info;
    }

    /**
     * Get preferred zone order based on trip type
     * Zone 1 = Back, Zone N = Front
     */
    private function getZonePreferences(int $numZones, string $troncon): array
    {
        $zones = range(1, $numZones);
        
        if ($troncon === 'long') {
            // Long trips: Prefer Back (1) to Front (N)
            // Order: 1, 2, 3, ..., N
            return $zones;
        } elseif ($troncon === 'short') {
            // Short trips: Prefer Front (N) to Back (1)
            // Order: N, N-1, ..., 1
            return array_reverse($zones);
        } else {
            // Medium trips: Prefer Middle zones
            // Algorithm: Start from middle, expand outwards
            // Bias towards Back (lower numbers) slightly?
            // Example N=4: Mid=2.5. Order: 2, 3, 1, 4
            
            $preferences = [];
            $mid = $numZones / 2;
            
            // Create array of zones with their distance to middle
            $zoneDistances = [];
            foreach ($zones as $z) {
                $zoneDistances[$z] = abs($z - $mid);
            }
            
            // Sort by distance to middle (ascending)
            // If distances equal, prefer lower zone number (Back)
            usort($zones, function($a, $b) use ($zoneDistances) {
                $distA = $zoneDistances[$a];
                $distB = $zoneDistances[$b];
                
                if (abs($distA - $distB) < 0.001) {
                    return $a <=> $b; // Prefer lower number (Back) if equal distance
                }
                return $distA <=> $distB;
            });
            
            return $zones;
        }
    }

    /**
     * Calculate seat score based on Dynamic Zones + Door Proximity
     */
    private function calculateDynamicZoneScore(
        int $seatNumber,
        string $troncon,
        int $destinationIndex,
        int $numZones,
        array $zonePreferences,
        int $totalSeats,
        array $doorPositions,
        array $seatMapInfo,
        Collection $occupiedSeatsData,
        string $routeId,
        bool $isReversed = false
    ): array {
        $score = 100;
        $reason = '';

        // 1. Partitioned Destination Zoning
        // Total stops including departure. Destinations = N = totalStops - 1.
        // We use $numZones which is already calculated as totalStops - 1.
        $seatsPerZone = $totalSeats / max(1, $numZones);
        
        // Passenger's Target Zone (1-indexed based on destination order)
        // If first destination stop is index 1, targetZone = 1.
        $targetZone = $destinationIndex; 
        
        // Which zone does THIS seat belong to?
        $seatRank = $seatMapInfo['seats'][$seatNumber]['proximity_rank'] ?? 999;
        $seatZone = (int)ceil($seatRank / $seatsPerZone);
        $seatZone = max(1, min($numZones, $seatZone));

        // Scoring based on Zone Match
        if ($seatZone === $targetZone) {
            $score += 2000; // Massive bonus for being in the correct partition
            $reason = "Zone Destination Idéale (Zone $seatZone)";
        } else {
            // Very heavy penalty for cross-zone allocation to ensure "filling" logic
            $zoneDiff = abs($seatZone - $targetZone);
            $score -= 1000 + ($zoneDiff * 500); 
            $reason = "Zone $seatZone (Réservé Destination $seatZone)";
        }

        // 2. Intra-Zone Order & Comfort
        // Apply secondary factors within the zone
        if ($troncon === 'long') {
             // For long trips, favor higher seat numbers (usually further back/quieter)
             $score += ($seatNumber / $totalSeats) * 50;
        }

        // 3. DOOR PROXIMITY (Refined for intra-zone ranking)
        $minDistanceToDoor = PHP_INT_MAX;
        $seatPos = $seatMapInfo['seats'][$seatNumber] ?? null;
        if ($seatPos) {
            foreach ($seatMapInfo['doors'] as $door) {
                $distance = abs($seatPos['row'] - $door['row']) + abs($seatPos['col'] - $door['col']);
                if ($distance < $minDistanceToDoor) {
                    $minDistanceToDoor = $distance;
                }
            }
        }
        
        $isNearDoor = ($minDistanceToDoor <= 1);
        
        // 4. Seat Type Logic
        $seatInfo = $seatMapInfo['seats'][$seatNumber] ?? ['type' => 'standard', 'adjacent_aisle_seats' => []];
        $seatType = $seatInfo['type'];
        
        if ($seatType === 'aisle') {
            $score += 30;
            $reason .= ' + Couloir';
        } elseif ($seatType === 'window' && !$isNearDoor) {
            $score -= 20;
            $reason .= ' - Fenêtre';
        }
        
        // 5. CRITICAL: Anti-Blocking Logic
        $wouldBlockPassengers = false;
        
        if ($seatType === 'window') {
            $aisleSeats = $seatInfo['adjacent_aisle_seats'];
            foreach ($aisleSeats as $aisleSeat) {
                if ($occupiedSeatsData->has($aisleSeat)) {
                    $occupantDestIndex = $this->getStopIndex($routeId, $occupiedSeatsData[$aisleSeat]->to_stop_id, $isReversed);
                    if ($occupantDestIndex < $destinationIndex) {
                        $wouldBlockPassengers = true;
                        break;
                    }
                }
            }
        }
        
        if ($wouldBlockPassengers) {
            $score -= 200; // VERY heavy penalty (increased from 100)
            $reason = 'Bloquerait un passager';
        }

        return [
            'score' => round($score, 2),
            'reason' => $reason
        ];
    }

    /**
     * Obtient l'index d'un arrêt dans un trajet
     * 
     * @param string $routeId ID du trajet
     * @param string $stopId ID de l'arrêt
     * @param bool $isReversed Whether the trip is in reverse direction
     * @return int Index de l'arrêt (1 for first destination, higher for further destinations)
     */
    private function getStopIndex(string $routeId, string $stopId, bool $isReversed = false): int
    {
        $stopOrder = RouteStopOrder::where('route_id', $routeId)
            ->where('stop_id', $stopId)
            ->first();

        if (!$stopOrder) {
            return 0;
        }

        $originalIndex = $stopOrder->stop_index;

        if ($isReversed) {
            // For reversed trips, invert the index
            // Example: Route has 5 stops (indices 0-4)
            // Bondoukou is index 4 (last), Agnibilékrou is index 3
            // For reversed trip, Bondoukou becomes 0 (start), Agnibilékrou becomes 1 (first dest)
            $totalStops = RouteStopOrder::where('route_id', $routeId)->count();
            // Invert: originalIndex 4 -> 0, 3 -> 1, 2 -> 2, 1 -> 3, 0 -> 4
            return ($totalStops - 1) - $originalIndex;
        }

        return $originalIndex;
    }

    /**
     * Obtient des statistiques sur l'occupation d'un voyage
     * 
     * @param string $tripId ID du voyage
     * @return array Statistiques d'occupation
     */
    public function getTripOccupancyStats(string $tripId): array
    {
        $trip = Trip::with('vehicle.vehicleType')->findOrFail($tripId);
        
        $totalSeats = $trip->vehicle->vehicleType->seat_count;
        $occupiedSeats = Ticket::where('trip_id', $tripId)
            ->where('status', '!=', 'cancelled')
            ->count();

        $occupancyRate = $totalSeats > 0 ? ($occupiedSeats / $totalSeats) * 100 : 0;

        return [
            'total_seats' => $totalSeats,
            'occupied_seats' => $occupiedSeats,
            'available_seats' => $totalSeats - $occupiedSeats,
            'occupancy_rate' => round($occupancyRate, 2),
            'booking_type' => $trip->booking_type,
            'vehicle_type' => $trip->vehicle->vehicleType->name
        ];
    }
}
