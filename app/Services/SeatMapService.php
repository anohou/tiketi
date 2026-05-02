<?php

namespace App\Services;

class SeatMapService
{
    /**
     * Ensures the seat map is a 2D grid.
     * If metadata is provided, it generates the grid.
     */
    public function ensureGrid(array $seatMap, array $context = []): array
    {
        // Check if it's already a 2D grid (array of arrays where first element is an array)
        if (! empty($seatMap) && isset($seatMap[0]) && is_array($seatMap[0])) {
            return $seatMap;
        }

        // If it's metadata (e.g., from seeder), generate the grid
        if (isset($seatMap['rows']) || isset($context['seat_count']) || isset($context['total_capacity'])) {
            return $this->generateSeatMap(array_merge($seatMap, $context));
        }

        return [];
    }

    /**
     * Generates a 2D seat map grid based on specific parameters.
     */
    public function generateSeatMap(array $data): array
    {
        // If door_positions and seat_count are missing, calculate them
        if (isset($data['total_capacity']) && (!isset($data['door_positions']) || !isset($data['seat_count']))) {
            $metadata = $this->calculateMetadata($data);
            $data = array_merge($data, $metadata);
        }

        $configStr = $data['seat_configuration'] ?? '2+2';
        $parts = explode('+', $configStr);
        $leftCount = (int) ($parts[0] ?? 2);
        $rightCount = (int) ($parts[1] ?? 2);
        $slotsPerRow = $leftCount + $rightCount;

        $seatCount = (int) ($data['seat_count'] ?? 0);
        $doorPositions = $data['door_positions'] ?? [];
        $lastRowSeats = (int) ($data['last_row_seats'] ?? 5);

        $seatMap = [];
        $currentSeatNum = 1;
        $rowIndex = 0;

        // Calculate seats to fill before the last row
        $seatsToFill = $seatCount - $lastRowSeats;
        $filledSeats = 0;

        // Keep generating rows until we have filled all seats
        while ($filledSeats < $seatsToFill) {
            $row = [];

            // Calculate start slot for this row (1-based)
            $rowStartSlot = ($rowIndex - 1) * $slotsPerRow + 1;

            if ($rowIndex === 0) {
                // Driver Row
                $row[] = ['type' => 'driver', 'label' => 'Chauffeur'];
                for ($i = 1; $i < $leftCount; $i++) {
                    $row[] = ['type' => 'empty'];
                }
            } else {
                // Standard Row Left
                for ($i = 0; $i < $leftCount; $i++) {
                    $currentSlot = $rowStartSlot + $i;
                    if (in_array($currentSlot, $doorPositions)) {
                        $row[] = ['type' => 'door'];
                    } elseif ($filledSeats < $seatsToFill) {
                        $row[] = ['type' => 'seat', 'number' => (string) $currentSeatNum++];
                        $filledSeats++;
                    } else {
                        $row[] = ['type' => 'empty'];
                    }
                }
            }

            // Aisle
            $row[] = ['type' => 'aisle'];

            // Right Side
            if ($rowIndex === 0) {
                if (in_array(0, $doorPositions)) {
                    // Place empty cells first, then door at outer edge
                    for ($i = 1; $i < $rightCount; $i++) {
                        $row[] = ['type' => 'empty'];
                    }
                    $row[] = ['type' => 'door', 'label' => 'Porte'];
                } else {
                    for ($i = 0; $i < $rightCount; $i++) {
                        if ($filledSeats < $seatsToFill) {
                            $row[] = ['type' => 'seat', 'number' => (string) $currentSeatNum++];
                            $filledSeats++;
                        } else {
                            $row[] = ['type' => 'empty'];
                        }
                    }
                }
            } else {
                for ($i = 0; $i < $rightCount; $i++) {
                    $currentSlot = $rowStartSlot + $leftCount + $i;
                    if (in_array($currentSlot, $doorPositions)) {
                        $row[] = ['type' => 'door'];
                    } elseif ($filledSeats < $seatsToFill) {
                        $row[] = ['type' => 'seat', 'number' => (string) $currentSeatNum++];
                        $filledSeats++;
                    } else {
                        $row[] = ['type' => 'empty'];
                    }
                }
            }

            $seatMap[] = $row;
            $rowIndex++;
            
            // Safety break to prevent infinite loops if something goes wrong
            if ($rowIndex > 100) break;
        }

        // Last Row
        $remainingSeats = $seatCount - $filledSeats;
        if ($remainingSeats > 0) {
            $lastRow = [];
            for ($i = 0; $i < $remainingSeats; $i++) {
                $lastRow[] = ['type' => 'seat', 'number' => (string) $currentSeatNum++];
            }
            $seatMap[] = $lastRow;
        }

        return $seatMap;
    }

    /**
     * Calculates door positions and seat count based on total capacity and configuration.
     */
    public function calculateMetadata(array $data): array
    {
        $totalCapacity = (int) ($data['total_capacity'] ?? 0);
        $doorCount = (int) ($data['door_count'] ?? 1);
        $doorSide = $data['door_side'] ?? 'right';
        $doorWidth = (int) ($data['door_width'] ?? 2);
        
        $configStr = $data['seat_configuration'] ?? '2+2';
        $parts = explode('+', $configStr);
        $leftCount = (int) ($parts[0] ?? 2);
        $rightCount = (int) ($parts[1] ?? 2);
        $slotsPerRow = $leftCount + $rightCount;

        // Approximate number of rows (including row 0 which is handled specially)
        $approxRows = ceil($totalCapacity / $slotsPerRow);
        $doorPositions = [0]; // Front door always at 0

        if ($doorCount >= 2) {
            // Middle door: around middle row
            $middleRow = floor($approxRows / 2);
            $rowStartSlot = ($middleRow - 1) * $slotsPerRow + 1;
            
            if ($doorSide === 'right') {
                for ($i = 0; $i < min($doorWidth, $rightCount); $i++) {
                    $doorPositions[] = $rowStartSlot + $leftCount + $i;
                }
            } else {
                for ($i = 0; $i < min($doorWidth, $leftCount); $i++) {
                    $doorPositions[] = $rowStartSlot + $i;
                }
            }
        }

        if ($doorCount >= 3) {
            // Back door: moved forward by 2 rows (now -4 from the end)
            $backRow = $approxRows - 4;
            if ($backRow > floor($approxRows / 2)) {
                $rowStartSlot = ($backRow - 1) * $slotsPerRow + 1;
                
                if ($doorSide === 'right') {
                    for ($i = 0; $i < min($doorWidth, $rightCount); $i++) {
                        $doorPositions[] = $rowStartSlot + $leftCount + $i;
                    }
                } else {
                    for ($i = 0; $i < min($doorWidth, $leftCount); $i++) {
                        $doorPositions[] = $rowStartSlot + $i;
                    }
                }
            }
        }

        $doorPositions = array_values(array_unique($doorPositions));
        sort($doorPositions);

        return [
            'door_positions' => $doorPositions,
            'seat_count' => $totalCapacity - count($doorPositions),
            'last_row_seats' => ($configStr === '3+2') ? 6 : 5,
        ];
    }
}
