<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vehicleTypes = config('transport.vehicle_types', []);
        $seatMapService = new SeatMapService;

        foreach ($vehicleTypes as $typeData) {
            // Generate metadata and seat map using the service
            $metadata = $seatMapService->calculateMetadata($typeData);
            $seatMap = $seatMapService->generateSeatMap(array_merge($typeData, $metadata));

            VehicleType::updateOrCreate(
                ['name' => $typeData['name']],
                [
                    'seat_count' => $metadata['seat_count'],
                    'seat_configuration' => $typeData['seat_configuration'] ?? '2+2',
                    'door_count' => $typeData['door_count'] ?? 1,
                    'door_positions' => $metadata['door_positions'],
                    'door_side' => $typeData['door_side'] ?? 'right',
                    'door_width' => $typeData['door_width'] ?? 2,
                    'last_row_seats' => $metadata['last_row_seats'],
                    'svg_template_path' => $typeData['svg_template_path'] ?? 'bus',
                    'seat_map' => $seatMap,
                    'active' => true,
                ]
            );
        }
    }
}
