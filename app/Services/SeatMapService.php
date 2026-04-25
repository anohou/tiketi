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
        if (isset($seatMap['rows']) || isset($context['seat_count'])) {
            return $this->generateSeatMap(array_merge($seatMap, $context));
        }

        return [];
    }

    /**
     * Generates a 2D seat map grid based on specific parameters.
     */
    public function generateSeatMap(array $data): array
    {
        $seatCount = (int) ($data['seat_count'] ?? 0);
        $configStr = $data['seat_configuration'] ?? '2+2';
        $doorPositions = $data['door_positions'] ?? [];
        $lastRowSeats = (int) ($data['last_row_seats'] ?? 5);

        // Parse configuration
        $parts = explode('+', $configStr);
        $leftCount = (int) ($parts[0] ?? 2);
        $rightCount = (int) ($parts[1] ?? 2);

        $seatMap = [];
        $currentSeatNum = 1;
        $rowIndex = 0;

        // Calculate seats to fill before the last row
        $seatsToFill = $seatCount - $lastRowSeats;
        $filledSeats = 0;
        $slotsPerRow = $leftCount + $rightCount;

        // Keep generating rows until we have filled all seats
        while ($filledSeats < $seatsToFill) {
            $row = [];

            // Row 0 is often considered the driver row in this app's logic
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
