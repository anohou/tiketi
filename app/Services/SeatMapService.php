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
        $configStr = $data['seat_configuration'] ?? '2+2';
        $parts = explode('+', $configStr);
        $leftCount = (int) ($parts[0] ?? 2);
        $rightCount = (int) ($parts[1] ?? 2);
        $slotsPerRow = $leftCount + $rightCount;

        // Smart parameter calculation if total_capacity is provided instead of seat_count
        if (isset($data['total_capacity']) && !isset($data['seat_count'])) {
            $totalCapacity = (int)$data['total_capacity'];
            $doorCount = (int)($data['door_count'] ?? 2);
            
            // Calculate approximate number of rows to find door positions
            $approxRows = ceil($totalCapacity / $slotsPerRow);
            $doorPositions = [0]; // Front door always at 0
            
            if ($doorCount >= 2) {
                // Middle door: around middle row, right side
                $middleRow = floor($approxRows / 2);
                $rowStartSlot = ($middleRow - 1) * $slotsPerRow + 1;
                $doorPositions[] = $rowStartSlot + $leftCount; // Inner right
                $doorPositions[] = $rowStartSlot + $leftCount + 1; // Outer right
            }
            
            if ($doorCount >= 3) {
                // Back door: 2 rows before last row, right side
                $backRow = $approxRows - 2;
                if ($backRow > floor($approxRows / 2)) {
                    $rowStartSlot = ($backRow - 1) * $slotsPerRow + 1;
                    $doorPositions[] = $rowStartSlot + $leftCount;
                    $doorPositions[] = $rowStartSlot + $leftCount + 1;
                }
            }
            
            $data['door_positions'] = array_unique($doorPositions);
            $data['seat_count'] = $totalCapacity - count($data['door_positions']);
            
            // Special case for last row seats based on configuration
            if (!isset($data['last_row_seats'])) {
                $data['last_row_seats'] = ($configStr === '3+2') ? 6 : 5;
            }
        }

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
            if ($rowIndex > 50) break;
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
}
