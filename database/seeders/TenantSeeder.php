<?php

namespace Database\Seeders;

use App\Models\CrewMember;
use App\Models\Destination;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStopOrder;
use App\Models\Station;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Vehicle Types first
        $this->call(VehicleTypeSeeder::class);

        // 2. Seed Villes (Destinations)
        $this->call(DestinationSeeder::class);

        // 3. Seed Gares (Stations)
        $garesConfig = config('transport.gares_par_ville', []);
        $createdStations = [];
        foreach ($garesConfig as $villeName => $gares) {
            $destination = Destination::where('name', $villeName)->first();
            if (! $destination) {
                continue;
            }

            foreach ($gares as $gare) {
                $createdStations[$gare['code']] = Station::updateOrCreate(
                    ['code' => $gare['code']],
                    [
                        'name' => $gare['name'],
                        'city' => $villeName,
                        'destination_id' => $destination->id,
                        'active' => true,
                    ]
                );
            }
        }

        // 4. Create Users (Default credentials matching STARTUP.md)
        $usersConfig = [
            [
                'email' => 'admin@transport.ci',
                'name' => 'Administrateur',
                'role' => 'admin',
                'telephone' => '+225 0701234567',
            ],
            [
                'email' => 'superviseur@transport.ci',
                'name' => 'Superviseur Abidjan',
                'role' => 'supervisor',
                'telephone' => '+225 0702345678',
                'station_code' => 'ABJ-NORD',
            ],
            [
                'email' => 'superviseur.second@transport.ci',
                'name' => 'Second Superviseur',
                'role' => 'supervisor',
                'telephone' => '+225 0702345679',
                'station_codes' => ['DIV-MAIN', 'GAG-MAIN'],
            ],
            [
                'email' => 'guichet.abidjan@transport.ci',
                'name' => 'Vendeur Abidjan',
                'role' => 'seller',
                'telephone' => '+225 0703456789',
                'station_code' => 'ABJ-NORD',
            ],
            [
                'email' => 'guichet.yamoussoukro@transport.ci',
                'name' => 'Vendeur Yamoussoukro',
                'role' => 'seller',
                'telephone' => '+225 0707890123',
                'station_code' => 'YAK-CENTRE',
            ],
            [
                'email' => 'guichet.divo@transport.ci',
                'name' => 'Vendeur Divo',
                'role' => 'seller',
                'telephone' => '+225 0703456790',
                'station_code' => 'DIV-MAIN',
            ],
            [
                'email' => 'guichet.gagnoa@transport.ci',
                'name' => 'Vendeur Gagnoa',
                'role' => 'seller',
                'telephone' => '+225 0703456791',
                'station_code' => 'GAG-MAIN',
            ],
            [
                'email' => 'guichet.bouake@transport.ci',
                'name' => 'Vendeur Bouake',
                'role' => 'seller',
                'telephone' => '+225 0708901234',
                'station_code' => 'BKE-MAIN',
            ],
            [
                'email' => 'guichet.korhogo@transport.ci',
                'name' => 'Vendeur Korhogo',
                'role' => 'seller',
                'telephone' => '+225 0704567890',
                'station_code' => 'KGO-MAIN',
            ],
            [
                'email' => 'comptable@transport.ci',
                'name' => 'Comptable',
                'role' => 'accountant',
                'telephone' => '+225 0705678901',
            ],
            [
                'email' => 'dg@transport.ci',
                'name' => 'Directeur Général',
                'role' => 'executive',
                'telephone' => '+225 0706789012',
            ],
        ];

        foreach ($usersConfig as $u) {
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'telephone' => $u['telephone'],
                    'password' => Hash::make('password'),
                    'role' => $u['role'],
                    'active' => true,
                ]
            );

            $stationCodes = [];
            if (isset($u['station_code'])) {
                $stationCodes[] = $u['station_code'];
            }
            if (isset($u['station_codes']) && is_array($u['station_codes'])) {
                $stationCodes = array_merge($stationCodes, $u['station_codes']);
            }

            foreach ($stationCodes as $code) {
                if (isset($createdStations[$code])) {
                    $station = $createdStations[$code];
                    UserStationAssignment::firstOrCreate([
                        'user_id' => $user->id,
                        'station_id' => $station->id,
                    ], [
                        'active' => true,
                    ]);
                }
            }
        }

        // 5. Seed Routes and Fares (calculating segment fares proportionally)
        $routesConfig = config('transport.routes_par_defaut', []);
        foreach ($routesConfig as $routeData) {
            $origin = $createdStations[$routeData['origin']] ?? null;
            $dest = $createdStations[$routeData['destination']] ?? null;

            if (! $origin || ! $dest) {
                continue;
            }

            $route = Route::updateOrCreate(
                ['name' => $routeData['name']],
                [
                    'origin_destination_id' => $origin->destination_id,
                    'target_destination_id' => $dest->destination_id,
                    'origin_station_id' => $origin->id,
                    'destination_station_id' => $dest->id,
                    'estimated_duration_minutes' => $routeData['estimated_duration_minutes'] ?? null,
                    'automatic_connection_allocation' => $routeData['automatic_connection_allocation'] ?? null,
                    'active' => true,
                ]
            );

            // Set up stop orders (dynamically or fallback)
            $stops = $routeData['stops'] ?? [$routeData['origin'], $routeData['destination']];
            foreach ($stops as $idx => $stopCode) {
                $stopStation = $createdStations[$stopCode] ?? null;
                if ($stopStation) {
                    RouteStopOrder::updateOrCreate(
                        ['route_id' => $route->id, 'stop_index' => $idx],
                        ['station_id' => $stopStation->id]
                    );
                }
            }

            // Set up Fares for all segments proportionally
            $totalHops = count($stops) - 1;
            for ($i = 0; $i < count($stops); $i++) {
                for ($j = $i + 1; $j < count($stops); $j++) {
                    $fromStation = $createdStations[$stops[$i]] ?? null;
                    $toStation = $createdStations[$stops[$j]] ?? null;

                    if ($fromStation && $toStation) {
                        $hops = $j - $i;
                        $amount = round(($routeData['fare'] / $totalHops) * $hops);

                        RouteFare::updateOrCreate(
                            [
                                'from_station_id' => $fromStation->id,
                                'to_station_id' => $toStation->id,
                            ],
                            [
                                'amount' => $amount,
                                'is_bidirectional' => true,
                                'active' => true,
                            ]
                        );
                    }
                }
            }
        }

        // 6. Seed sample Vehicles if none exist
        if (Vehicle::count() === 0) {
            $massaType = VehicleType::where('name', 'Massa (15 places)')->first();
            if ($massaType) {
                Vehicle::create([
                    'identifier' => 'MASSA-001',
                    'maker' => 'Toyota',
                    'vehicle_type_id' => $massaType->id,
                    'seat_count' => $massaType->seat_count,
                    'active' => true,
                ]);
                Vehicle::create([
                    'identifier' => 'MASSA-002',
                    'maker' => 'Toyota',
                    'vehicle_type_id' => $massaType->id,
                    'seat_count' => $massaType->seat_count,
                    'active' => true,
                ]);
                Vehicle::create([
                    'identifier' => 'MASSA-003',
                    'maker' => 'Toyota',
                    'vehicle_type_id' => $massaType->id,
                    'seat_count' => $massaType->seat_count,
                    'active' => true,
                ]);
            }

            $busType = VehicleType::where('name', 'Autocar 50 places (2+2)')->first();
            if ($busType) {
                Vehicle::create([
                    'identifier' => 'BUS-001',
                    'maker' => 'Volvo',
                    'vehicle_type_id' => $busType->id,
                    'seat_count' => $busType->seat_count,
                    'active' => true,
                ]);
            }
        }

        // 7. Seed sample Crew Members and assign them to vehicles
        $drivers = [
            [
                'name' => 'Koffi Kouadio',
                'phone' => '+2250501020304',
                'role' => 'driver',
                'license_number' => 'DRV-123456',
                'license_expiry_date' => Carbon::now()->addYears(3),
                'pin' => '123456',
            ],
            [
                'name' => 'Konan Yao',
                'phone' => '+2250502030405',
                'role' => 'driver',
                'license_number' => 'DRV-654321',
                'license_expiry_date' => Carbon::now()->addYears(3),
                'pin' => '123456',
            ],
        ];

        $assistants = [
            [
                'name' => 'Kouassi Amenan',
                'phone' => '+2250503040506',
                'role' => 'assistant',
                'pin' => '123456',
            ],
            [
                'name' => 'Yao Brou',
                'phone' => '+2250504050607',
                'role' => 'assistant',
                'pin' => '123456',
            ],
        ];

        $createdDrivers = [];
        $createdAssistants = [];

        foreach ($drivers as $d) {
            $createdDrivers[] = CrewMember::updateOrCreate(
                ['phone' => $d['phone']],
                [
                    'name' => $d['name'],
                    'role' => $d['role'],
                    'license_number' => $d['license_number'],
                    'license_expiry_date' => $d['license_expiry_date'],
                    'pin' => Hash::make($d['pin']),
                    'active' => true,
                ]
            );
        }

        foreach ($assistants as $a) {
            $createdAssistants[] = CrewMember::updateOrCreate(
                ['phone' => $a['phone']],
                [
                    'name' => $a['name'],
                    'role' => $a['role'],
                    'pin' => Hash::make($a['pin']),
                    'active' => true,
                ]
            );
        }

        $vehicles = Vehicle::all();
        foreach ($vehicles as $index => $vehicle) {
            $driver = $createdDrivers[$index % count($createdDrivers)];
            $assistant = $createdAssistants[$index % count($createdAssistants)];

            VehicleCrewAssignment::updateOrCreate(
                [
                    'vehicle_id' => $vehicle->id,
                    'crew_member_id' => $driver->id,
                ],
                [
                    'role' => 'driver',
                    'assigned_from' => Carbon::today()->startOfDay(),
                    'assigned_to' => null,
                ]
            );

            VehicleCrewAssignment::updateOrCreate(
                [
                    'vehicle_id' => $vehicle->id,
                    'crew_member_id' => $assistant->id,
                ],
                [
                    'role' => 'assistant',
                    'assigned_from' => Carbon::today()->startOfDay(),
                    'assigned_to' => null,
                ]
            );
        }

        // 8. Seed deterministic trips and connection cases.
        $this->call(CorrespondenceDemoSeeder::class);
    }
}
