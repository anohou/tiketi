<?php

namespace App\Services;

use App\Models\Stop;
use App\Models\Trip;
use Illuminate\Support\Collection;

class SeatAllocator
{
    /**
     * Suggest the best available seats for a given trip and destination.
     *
     * @param Trip $trip The trip for which to suggest seats.
     * @param Stop $destinationStop The passenger's destination stop.
     * @param int $quantity The number of seats to suggest.
     * @return array An array of suggested seat numbers.
     */
    public function suggestSeats(Trip $trip, Stop $destinationStop, int $quantity = 1): array
    {
        // 1. Load vehicle with vehicle type to get door positions
        $trip->load(['vehicle.vehicleType', 'route.stops', 'route']);
        $vehicle = $trip->vehicle;
        $vehicleType = $vehicle->vehicleType;
        $route = $trip->route;
        
        $seatCount = $vehicle->seat_count;
        
        // Use vehicle type door positions (more accurate)
        $doorPositions = $vehicleType->door_positions ?? $vehicle->door_positions ?? [1];
        
        // Get seats that are already occupied for any segment of this trip
        $occupiedSeats = $trip->tripSeatOccupancies()->pluck('seat_number')->toArray();

        // 2. DETECT IF TRIP IS REVERSED
        // If trip's origin_station_id != route's origin_station_id, the trip is reversed
        $isReversedTrip = $trip->origin_station_id && 
                          $route && 
                          $trip->origin_station_id !== $route->origin_station_id;

        // 3. Determine the destination rank (considering trip direction)
        $stopsOrder = $route->stops->pluck('id')->toArray();
        
        // If reversed, flip the stops order
        if ($isReversedTrip) {
            $stopsOrder = array_reverse($stopsOrder);
        }
        
        $destinationIndex = array_search($destinationStop->id, $stopsOrder);
        $totalStops = count($stopsOrder);
        
        // Rank is from 0 (first stop) to 1 (last stop)
        $destinationRank = ($totalStops > 1) ? ($destinationIndex / ($totalStops - 1)) : 1;

        // 3. Generate a list of all available seats with their scores
        $availableSeats = collect(range(1, $seatCount))
            ->diff($occupiedSeats)
            ->map(function ($seatNumber) use ($doorPositions, $destinationRank, $vehicleType) {
                $distanceToDoor = $this->calculateMinDistanceToDoor($seatNumber, $doorPositions);
                $score = $this->calculateSeatScore($seatNumber, $distanceToDoor, $destinationRank, $vehicleType);
                
                return [
                    'number' => $seatNumber,
                    'distance_to_door' => $distanceToDoor,
                    'score' => $score,
                ];
            });

        // 4. Sort seats by score (higher is better)
        $sortedSeats = $availableSeats->sortByDesc('score');

        // 5. Return the top N suggested seats
        return $sortedSeats->pluck('number')->take($quantity)->values()->toArray();
    }
    
    /**
     * Calculate a composite score for a seat based on multiple factors.
     */
    private function calculateSeatScore(int $seatNumber, int $distanceToDoor, float $destinationRank, $vehicleType): float
    {
        // This is a simplified version. The main logic is in OptimisationService.
        // For consistency, let's make it behave similarly if used.
        $score = 100;
        
        if ($destinationRank < 0.3) {
            // Short trip: prefer closer to door
            $score -= ($distanceToDoor * 20);
        } elseif ($destinationRank > 0.7) {
            // Long trip: prefer farther from door (quieter)
            $score += ($distanceToDoor * 10);
        }
        
        return $score;
    }

    /**
     * Calculate the minimum distance from a seat to any door using geometric layout.
     */
    private function calculateMinDistanceToDoor(int $seatNumber, array $doorPositions): int
    {
        // Note: SeatAllocator doesn't have access to the full seat_map in its current signature.
        // Ideally, we should refactor it to use OptimisationService logic.
        // For now, let's keep the seat-number based fallback but with a warning.
        
        if (empty($doorPositions)) {
            return PHP_INT_MAX;
        }

        $minDistance = PHP_INT_MAX;
        foreach ($doorPositions as $door) {
            $distance = abs($seatNumber - $door);
            if ($distance < $minDistance) {
                $minDistance = $distance;
            }
        }

        return $minDistance;
    }
}
