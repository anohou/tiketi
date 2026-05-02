<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class VehicleTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vehicleTypes = config('transport.vehicle_types', []);
        $seatMapService = new SeatMapService();

        foreach ($vehicleTypes as $typeData) {
            // Calculate door positions and seat count based on total_capacity
            $config = $typeData['seat_configuration'] ?? '2+2';
            $parts = explode('+', $config);
            $slotsPerRow = array_sum($parts);
            
            // Generate the seat map using the service
            $seatMap = $seatMapService->generateSeatMap($typeData);
            
            // Extract door positions for the DB
            $doorPositions = [0]; // Driver door is always 0
            if (($typeData['door_count'] ?? 1) >= 2) {
                // Middle door
                $approxRows = ceil(($typeData['total_capacity'] ?? 30) / $slotsPerRow);
                $mRow = floor($approxRows / 2);
                $start = ($mRow - 1) * $slotsPerRow + 1;
                $doorPositions[] = $start + $parts[0];
            }
            if (($typeData['door_count'] ?? 1) >= 3) {
                // Back door
                $approxRows = ceil(($typeData['total_capacity'] ?? 30) / $slotsPerRow);
                $bRow = $approxRows - 1;
                $start = ($bRow - 1) * $slotsPerRow + 1;
                $doorPositions[] = $start + $parts[0];
            }

            $seatCount = ($typeData['total_capacity'] ?? 30) - count($doorPositions);

            VehicleType::updateOrCreate(
                ['name' => $typeData['name']],
                [
                    'seat_count' => $seatCount,
                    'seat_configuration' => $config,
                    'door_count' => $typeData['door_count'] ?? 1,
                    'door_positions' => $doorPositions,
                    'last_row_seats' => ($config === '3+2') ? 6 : 5,
                    'svg_template_path' => $typeData['svg_template_path'] ?? 'bus',
                    'seat_map' => $seatMap,
                    'active' => true,
                ]
            );
        }
    }
}
