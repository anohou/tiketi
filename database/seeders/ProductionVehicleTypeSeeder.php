<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use App\Services\SeatMapService;
use Illuminate\Database\Seeder;

class ProductionVehicleTypeSeeder extends Seeder
{
    /**
     * Run the production-safe vehicle type seeds.
     */
    public function run(): void
    {
        $vehicleTypes = config('transport.production_vehicle_types', []);
        $seatMapService = new SeatMapService;

        foreach ($vehicleTypes as $typeData) {
            $seatMap = $seatMapService->generateSeatMap($typeData);

            VehicleType::updateOrCreate(
                ['name' => $typeData['name']],
                [
                    'seat_count' => $typeData['seat_count'],
                    'seat_configuration' => $typeData['seat_configuration'] ?? '2+2',
                    'door_count' => $typeData['door_count'] ?? count($typeData['door_positions'] ?? []),
                    'door_positions' => $typeData['door_positions'] ?? [],
                    'door_side' => $typeData['door_side'] ?? 'right',
                    'door_width' => $typeData['door_width'] ?? 2,
                    'last_row_seats' => $typeData['last_row_seats'] ?? 5,
                    'svg_template_path' => $typeData['svg_template_path'] ?? 'bus',
                    'seat_map' => $seatMap,
                    'active' => $typeData['active'] ?? true,
                ]
            );
        }
    }
}
