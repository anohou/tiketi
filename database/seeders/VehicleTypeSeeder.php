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
            $metadata = $seatMapService->calculateMetadata($typeData);
            $seatCount = $typeData['seat_count'] ?? $metadata['seat_count'];
            $doorPositions = $typeData['door_positions'] ?? $metadata['door_positions'];
            $lastRowSeats = $typeData['last_row_seats'] ?? $metadata['last_row_seats'];
            $doorCount = $typeData['door_count'] ?? count($doorPositions);
            $doorSide = $typeData['door_side'] ?? 'right';
            $doorWidth = $typeData['door_width'] ?? 2;
            $svgTemplatePath = $typeData['svg_template_path'] ?? 'bus';

            $seatMap = $seatMapService->generateSeatMap([
                ...$typeData,
                'seat_count' => $seatCount,
                'door_positions' => $doorPositions,
                'last_row_seats' => $lastRowSeats,
                'door_count' => $doorCount,
                'door_side' => $doorSide,
                'door_width' => $doorWidth,
            ]);

            VehicleType::updateOrCreate(
                ['name' => $typeData['name']],
                [
                    'seat_count' => $seatCount,
                    'seat_configuration' => $typeData['seat_configuration'] ?? '2+2',
                    'door_count' => $doorCount,
                    'door_positions' => $doorPositions,
                    'door_side' => $doorSide,
                    'door_width' => $doorWidth,
                    'last_row_seats' => $lastRowSeats,
                    'svg_template_path' => $svgTemplatePath,
                    'seat_map' => $seatMap,
                    'active' => true,
                ]
            );
        }
    }
}
