<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vehicleTypes = [
            [
                'name' => 'Minibus 15 places',
                'seat_count' => 15,
                'seat_configuration' => '2+1',
                'door_count' => 1,
                'door_positions' => [0], // Porte à l'avant
                'svg_template_path' => 'minibus_15',
                'seat_map' => $this->generateSeatMap(15, '2+1'),
            ],
            [
                'name' => 'Bus 30 places',
                'seat_count' => 30,
                'seat_configuration' => '2+2',
                'door_count' => 2,
                'door_positions' => [0, 13, 14], // Portes avant et milieu
                'svg_template_path' => 'bus_30',
                'seat_map' => $this->generateSeatMap(30, '2+2'),
            ],
            [
                'name' => 'Bus 50 places',
                'seat_count' => 47,
                'seat_configuration' => '2+2',
                'door_count' => 2,
                'door_positions' => [0, 26, 27], // Porte avant (1) et porte milieu (23-24 combined)
                'svg_template_path' => 'bus_50',
                'seat_map' => (function () {
                    $map = [];
                    $seatNum = 1;
                    // 9 rows of 4 seats
                    for ($row = 1; $row <= 9; $row++) {
                        $rowSeats = [];
                        for ($col = 1; $col <= 4; $col++) {
                            $rowSeats[] = ['number' => $seatNum++, 'row' => $row, 'col' => $col];
                        }
                        $map[] = $rowSeats;
                    }
                    // Last row (Row 10) with 5 seats
                    $lastRow = [];
                    for ($col = 1; $col <= 5; $col++) {
                        $lastRow[] = ['number' => $seatNum++, 'row' => 10, 'col' => $col];
                    }
                    $map[] = $lastRow;

                    return $map;
                })(),
            ],
            [
                'name' => 'Bus Double-Étage 80 places',
                'seat_count' => 67,
                'seat_configuration' => '2+2',
                'door_count' => 2,
                'door_positions' => [0, 29, 30, 54, 55], // Portes en bas
                'svg_template_path' => 'bus_double_echap_80',
                'seat_map' => (function () {
                    // Lower deck: 30 seats (rows of 4 + some spaces for stairs)
                    $lowerDeck = [];
                    $seatNum = 1;
                    for ($row = 1; $row <= 7; $row++) {
                        $lowerDeck[] = [
                            ['type' => 'seat', 'number' => $seatNum++],
                            ['type' => 'seat', 'number' => $seatNum++],
                            ['type' => 'aisle'],
                            ['type' => 'seat', 'number' => $seatNum++],
                            ['type' => 'seat', 'number' => $seatNum++],
                        ];
                    }
                    $lowerDeck[] = [
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'aisle'],
                        ['type' => 'empty'], // Escalier
                        ['type' => 'empty'],
                    ];

                    // Upper deck: 50 seats
                    $upperDeck = [];
                    for ($row = 1; $row <= 12; $row++) {
                        $upperDeck[] = [
                            ['type' => 'seat', 'number' => $seatNum++],
                            ['type' => 'seat', 'number' => $seatNum++],
                            ['type' => 'aisle'],
                            ['type' => 'seat', 'number' => $seatNum++],
                            ['type' => 'seat', 'number' => $seatNum++],
                        ];
                    }
                    $upperDeck[] = [
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'empty'], // Escalier
                        ['type' => 'empty'],
                        ['type' => 'empty'],
                    ];

                    return [
                        'lower_deck' => $lowerDeck,
                        'upper_deck' => $upperDeck,
                    ];
                })(),
            ],
        ];

        foreach ($vehicleTypes as $vehicleType) {
            \App\Models\VehicleType::updateOrCreate(
                ['name' => $vehicleType['name']],
                $vehicleType
            );
        }
    }

    /**
     * Génère un plan de sièges basique pour un véhicule
     */
    private function generateSeatMap(int $seatCount, string $configuration): array
    {
        $seatsPerRow = array_sum(array_map('intval', explode('+', $configuration)));
        $rows = ceil($seatCount / $seatsPerRow);
        $seatMap = [];
        $seatNumber = 1;

        for ($row = 0; $row < $rows; $row++) {
            $rowSeats = [];
            for ($col = 0; $col < $seatsPerRow && $seatNumber <= $seatCount; $col++) {
                $rowSeats[] = [
                    'number' => $seatNumber,
                    'row' => $row + 1,
                    'col' => $col + 1,
                ];
                $seatNumber++;
            }
            if (! empty($rowSeats)) {
                $seatMap[] = $rowSeats;
            }
        }

        return $seatMap;
    }
}
