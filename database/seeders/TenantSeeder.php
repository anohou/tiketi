<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Station;
use App\Models\Route;
use App\Models\RouteStopOrder;

use App\Models\RouteFare;
use App\Models\VehicleType;
use App\Models\Vehicle;
use App\Models\Trip;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // ==========================================
        // UTILISATEURS
        // ==========================================
        $admin = User::create([
            'name' => 'Administrateur',
            'email' => 'admin@transport.ci',
            'telephone' => '+225 0701234567',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $supervisor = User::create([
            'name' => 'Superviseur Gare',
            'email' => 'superviseur@transport.ci',
            'telephone' => '+225 0702345678',
            'password' => Hash::make('password'),
            'role' => 'supervisor',
        ]);

        $seller1 = User::create([
            'name' => 'Guichetier Abidjan',
            'email' => 'guichet.abidjan@transport.ci',
            'telephone' => '+225 0703456789',
            'password' => Hash::make('password'),
            'role' => 'seller',
        ]);

        $seller2 = User::create([
            'name' => 'Guichetier Korhogo',
            'email' => 'guichet.korhogo@transport.ci',
            'telephone' => '+225 0704567890',
            'password' => Hash::make('password'),
            'role' => 'seller',
        ]);

        // New Roles: Accountant & Executive
        $accountant = User::create([
            'name' => 'Comptable',
            'email' => 'comptable@transport.ci',
            'telephone' => '+225 0705678901',
            'password' => Hash::make('password'),
            'role' => 'accountant',
        ]);

        $executive = User::create([
            'name' => 'Directeur Général',
            'email' => 'dg@transport.ci',
            'telephone' => '+225 0706789012',
            'password' => Hash::make('password'),
            'role' => 'executive',
        ]);

        // ==========================================
        // STATIONS AVEC COORDONNÉES GPS RÉELLES
        // ==========================================
        $abidjan = Station::create([
            'name' => 'Abidjan - Adjamé',
            'code' => 'ABJ',
            'city' => 'Abidjan',
            'address' => 'Gare Adjamé, Abidjan',
            'latitude' => 5.3600,
            'longitude' => -4.0083,
            'active' => true
        ]);

        $abidjan1 = Station::create([
            'name' => 'Abidjan - Yopougon Gesco',
            'code' => 'YOP',
            'city' => 'Abidjan',
            'address' => 'Gare Yopougon Gesco, Abidjan',
            'latitude' => 5.3600,
            'longitude' => -4.0083,
            'active' => true
        ]);

        $yamoussoukro = Station::create([
            'name' => 'Yamoussoukro',
            'code' => 'YAM',
            'city' => 'Yamoussoukro',
            'address' => 'Gare Yamoussoukro',
            'latitude' => 6.8276,
            'longitude' => -5.2893,
            'active' => true
        ]);

        $bouake = Station::create([
            'name' => 'Bouaké',
            'code' => 'BKE',
            'city' => 'Bouaké',
            'address' => 'Gare Bouaké',
            'latitude' => 7.6944,
            'longitude' => -5.0300,
            'active' => true
        ]);

        $katiola = Station::create([
            'name' => 'Katiola',
            'code' => 'KAT',
            'city' => 'Katiola',
            'address' => 'Gare Katiola',
            'latitude' => 8.1372,
            'longitude' => -5.0978,
            'active' => true
        ]);

        $korhogo = Station::create([
            'name' => 'Korhogo',
            'code' => 'KOR',
            'city' => 'Korhogo',
            'address' => 'Gare Korhogo',
            'latitude' => 9.4580,
            'longitude' => -5.6294,
            'active' => true
        ]);

        $adzope = Station::create([
            'name' => 'Adzopé',
            'code' => 'ADZ',
            'city' => 'Adzopé',
            'address' => 'Gare Adzopé',
            'latitude' => 6.1069,
            'longitude' => -3.8589,
            'active' => true
        ]);

        $abengourou = Station::create([
            'name' => 'Abengourou',
            'code' => 'ABG',
            'city' => 'Abengourou',
            'address' => 'Gare Abengourou',
            'latitude' => 7.1333,
            'longitude' => -3.2000,
            'active' => true
        ]);

        $agnibilekrou = Station::create([
            'name' => 'Agnibilékrou',
            'code' => 'AGL',
            'city' => 'Agnibilékrou',
            'address' => 'Gare Agnibilékrou',
            'latitude' => 7.1333,
            'longitude' => -3.2000,
            'active' => true
        ]);

        $bondoukou = Station::create([
            'name' => 'Bondoukou',
            'code' => 'BDK',
            'city' => 'Bondoukou',
            'address' => 'Gare Bondoukou',
            'latitude' => 8.0403,
            'longitude' => -2.8000,
            'active' => true
        ]);

        $daoukro = Station::create([
            'name' => 'Daoukro',
            'code' => 'DAU',
            'city' => 'Daoukro',
            'address' => 'Gare Daoukro',
            'latitude' => 8.0403,
            'longitude' => -2.8000,
            'active' => true
        ]);



        // ==========================================
        // TYPES DE VÉHICULES (6 types: 15, 30, 50x2, 50x3, 70x2, 70x3)
        // ==========================================

        // Initialize SeatMapService
        $seatMapService = new \App\Services\SeatMapService();

        // Minibus 15 places (2+1, 1 porte)
        $minibus15 = VehicleType::create([
            'name' => 'Minibus 15 places',
            'seat_count' => 15,
            'seat_configuration' => '2+1',
            'door_count' => 1,
            'door_positions' => [1],
            'seat_map' => $seatMapService->generateSeatMap([
                'seat_count' => 15,
                'seat_configuration' => '2+1',
                'door_positions' => [1],
                'last_row_seats' => 5
            ]),
            'svg_template_path' => 'svg/vehicles/minibus_15.svg'
        ]);

        // Bus 30 places (2+2, 2 portes)
        $bus30 = VehicleType::create([
            'name' => 'Bus 30 places',
            'seat_count' => 30,
            'seat_configuration' => '2+2',
            'door_count' => 2,
            'door_positions' => [1, 16],
            'seat_map' => $seatMapService->generateSeatMap([
                'seat_count' => 30,
                'seat_configuration' => '2+2',
                'door_positions' => [1, 16],
                'last_row_seats' => 5
            ]),
            'svg_template_path' => 'svg/vehicles/bus_30.svg'
        ]);

        // Bus 50 places configuration 2+2 (2 portes)
        $bus50_2x2 = VehicleType::create([
            'name' => 'Bus 50 places (2+2)',
            'seat_count' => 50,
            'seat_configuration' => '2+2',
            'door_count' => 2,
            'door_positions' => [1, 26],
            'seat_map' => $seatMapService->generateSeatMap([
                'seat_count' => 50,
                'seat_configuration' => '2+2',
                'door_positions' => [1, 26],
                'last_row_seats' => 5
            ]),
            'svg_template_path' => 'svg/vehicles/bus_50_2x2.svg'
        ]);

        // Bus 50 places configuration 3+2 (2 portes)
        $bus50_3x2 = VehicleType::create([
            'name' => 'Bus 50 places (3+2)',
            'seat_count' => 50,
            'seat_configuration' => '3+2',
            'door_count' => 2,
            'door_positions' => [1, 26],
            'seat_map' => $seatMapService->generateSeatMap([
                'seat_count' => 50,
                'seat_configuration' => '3+2',
                'door_positions' => [1, 26],
                'last_row_seats' => 5
            ]),
            'svg_template_path' => 'svg/vehicles/bus_50_3x2.svg'
        ]);

        // Bus 70 places configuration 2+2 (3 portes)
        $bus70_2x2 = VehicleType::create([
            'name' => 'Bus 70 places (2+2)',
            'seat_count' => 70,
            'seat_configuration' => '2+2',
            'door_count' => 3,
            'door_positions' => [1, 30, 55],
            'seat_map' => $seatMapService->generateSeatMap([
                'seat_count' => 70,
                'seat_configuration' => '2+2',
                'door_positions' => [1, 30, 55],
                'last_row_seats' => 5
            ]),
            'svg_template_path' => 'svg/vehicles/bus_70_2x2.svg'
        ]);

        // Bus 70 places configuration 3+2 (3 portes)
        $bus70_3x2 = VehicleType::create([
            'name' => 'Bus 70 places (3+2)',
            'seat_count' => 70,
            'seat_configuration' => '3+2',
            'door_count' => 3,
            'door_positions' => [1, 30, 55],
            'seat_map' => $seatMapService->generateSeatMap([
                'seat_count' => 70,
                'seat_configuration' => '3+2',
                'door_positions' => [1, 30, 55],
                'last_row_seats' => 5
            ]),
            'svg_template_path' => 'svg/vehicles/bus_70_3x2.svg'
        ]);

        // Bus Double-Étage 80 places
        $busDouble80 = VehicleType::create([
            'name' => 'Bus Double-Étage 80 places',
            'seat_count' => 80,
            'seat_configuration' => '2+2',
            'door_count' => 2,
            'door_positions' => [1, 20],
            'svg_template_path' => 'bus_double_echap_80',
            'seat_map' => (function() {
                // Lower deck: 30 seats
                $lowerDeck = [];
                $seatNum = 1;
                for ($row = 1; $row <= 7; $row++) {
                    $lowerDeck[] = [
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'aisle'],
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'seat', 'number' => $seatNum++]
                    ];
                }
                $lowerDeck[] = [
                    ['type' => 'seat', 'number' => $seatNum++],
                    ['type' => 'seat', 'number' => $seatNum++],
                    ['type' => 'aisle'],
                    ['type' => 'empty'],
                    ['type' => 'empty']
                ];
                
                // Upper deck: 50 seats
                $upperDeck = [];
                for ($row = 1; $row <= 12; $row++) {
                    $upperDeck[] = [
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'aisle'],
                        ['type' => 'seat', 'number' => $seatNum++],
                        ['type' => 'seat', 'number' => $seatNum++]
                    ];
                }
                $upperDeck[] = [
                    ['type' => 'seat', 'number' => $seatNum++],
                    ['type' => 'seat', 'number' => $seatNum++],
                    ['type' => 'empty'],
                    ['type' => 'empty'],
                    ['type' => 'empty']
                ];
                
                return [
                    'lower_deck' => $lowerDeck,
                    'upper_deck' => $upperDeck
                ];
            })(),
        ]);

        // ==========================================
        // VÉHICULES
        // ==========================================
        $vehiclesMini = [];
        for ($i = 1; $i <= 1; $i++) {
            $vehiclesMini[] = Vehicle::create([
                'identifier' => 'MIN-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'Toyota Hiace',
                'vehicle_type_id' => $minibus15->id,
                'seat_count' => 15
            ]);
        }

        $vehiclesBus30 = [];
        for ($i = 1; $i <= 1; $i++) {
            $vehiclesBus30[] = Vehicle::create([
                'identifier' => 'B30-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'Mercedes-Benz Sprinter',
                'vehicle_type_id' => $bus30->id,
                'seat_count' => 30
            ]);
        }

        $vehiclesBus50_2x2 = [];
        for ($i = 1; $i <= 2; $i++) {
            $vehiclesBus50_2x2[] = Vehicle::create([
                'identifier' => 'B50-2X2-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'Mercedes-Benz Tourismo',
                'vehicle_type_id' => $bus50_2x2->id,
                'seat_count' => 50
            ]);
        }

        $vehiclesBus50_3x2 = [];
        for ($i = 1; $i <= 2; $i++) {
            $vehiclesBus50_3x2[] = Vehicle::create([
                'identifier' => 'B50-3X2-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'Volvo 9700',
                'vehicle_type_id' => $bus50_3x2->id,
                'seat_count' => 50
            ]);
        }

        $vehiclesBus70_2x2 = [];
        for ($i = 1; $i <= 1; $i++) {
            $vehiclesBus70_2x2[] = Vehicle::create([
                'identifier' => 'B70-2X2-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'Scania Interlink',
                'vehicle_type_id' => $bus70_2x2->id,
                'seat_count' => 70
            ]);
        }

        $vehiclesBus70_3x2 = [];
        for ($i = 1; $i <= 2; $i++) {
            $vehiclesBus70_3x2[] = Vehicle::create([
                'identifier' => 'B70-3X2-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'MAN Lion Coach',
                'vehicle_type_id' => $bus70_3x2->id,
                'seat_count' => 70
            ]);
        }
        
        $vehiclesDouble80 = [];
        for ($i = 1; $i <= 1; $i++) {
            $vehiclesDouble80[] = Vehicle::create([
                'identifier' => 'DBL-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'maker' => 'Neoplan Skyliner',
                'vehicle_type_id' => $busDouble80->id,
                'seat_count' => 80
            ]);
        }

        // ==========================================
        // TRAJET 1: ABIDJAN → KORHOGO (525 km, 5 arrêts)
        // ==========================================
        $routeAbidjanKorhogo = Route::create([
            'name' => 'Abidjan → Korhogo',
            'origin_station_id' => $abidjan->id,
            'destination_station_id' => $korhogo->id,
            'active' => true
        ]);

        // Ordre des stations (anciennement arrêts)
        RouteStopOrder::create(['route_id' => $routeAbidjanKorhogo->id, 'station_id' => $abidjan->id, 'stop_index' => 0]);
        RouteStopOrder::create(['route_id' => $routeAbidjanKorhogo->id, 'station_id' => $yamoussoukro->id, 'stop_index' => 1]);
        RouteStopOrder::create(['route_id' => $routeAbidjanKorhogo->id, 'station_id' => $bouake->id, 'stop_index' => 2]);
        RouteStopOrder::create(['route_id' => $routeAbidjanKorhogo->id, 'station_id' => $katiola->id, 'stop_index' => 3]);
        RouteStopOrder::create(['route_id' => $routeAbidjanKorhogo->id, 'station_id' => $korhogo->id, 'stop_index' => 4]);

        // Tarifs (environ 20 FCFA/km)
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $yamoussoukro->id, 'amount' => 5000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $bouake->id, 'amount' => 7000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $katiola->id, 'amount' => 9000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $korhogo->id, 'amount' => 11000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $yamoussoukro->id, 'to_station_id' => $bouake->id, 'amount' => 2000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $yamoussoukro->id, 'to_station_id' => $katiola->id, 'amount' => 4000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $yamoussoukro->id, 'to_station_id' => $korhogo->id, 'amount' => 6000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $bouake->id, 'to_station_id' => $katiola->id, 'amount' => 2000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $bouake->id, 'to_station_id' => $korhogo->id, 'amount' => 4000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $katiola->id, 'to_station_id' => $korhogo->id, 'amount' => 2000, 'is_bidirectional' => true]);

        // ==========================================
        // TRAJET 2: ABIDJAN → BONDOUKOU (340 km, 5 arrêts incluant Abengourou)
        // ==========================================
        $routeAbidjanBondoukou = Route::create([
            'name' => 'Abidjan → Bondoukou',
            'origin_station_id' => $abidjan->id,
            'destination_station_id' => $bondoukou->id,
            'active' => true
        ]);

        // Ordre des stations
        RouteStopOrder::create(['route_id' => $routeAbidjanBondoukou->id, 'station_id' => $abidjan->id, 'stop_index' => 0]);
        RouteStopOrder::create(['route_id' => $routeAbidjanBondoukou->id, 'station_id' => $adzope->id, 'stop_index' => 1]);
        RouteStopOrder::create(['route_id' => $routeAbidjanBondoukou->id, 'station_id' => $abengourou->id, 'stop_index' => 2]);
        RouteStopOrder::create(['route_id' => $routeAbidjanBondoukou->id, 'station_id' => $agnibilekrou->id, 'stop_index' => 3]);
        RouteStopOrder::create(['route_id' => $routeAbidjanBondoukou->id, 'station_id' => $bondoukou->id, 'stop_index' => 4]);

        // Tarifs
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $adzope->id, 'amount' => 2500, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $abengourou->id, 'amount' => 4000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $agnibilekrou->id, 'amount' => 5500, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abidjan->id, 'to_station_id' => $bondoukou->id, 'amount' => 7000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $adzope->id, 'to_station_id' => $abengourou->id, 'amount' => 1500, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $adzope->id, 'to_station_id' => $agnibilekrou->id, 'amount' => 3000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $adzope->id, 'to_station_id' => $bondoukou->id, 'amount' => 4500, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abengourou->id, 'to_station_id' => $agnibilekrou->id, 'amount' => 1500, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $abengourou->id, 'to_station_id' => $bondoukou->id, 'amount' => 3000, 'is_bidirectional' => true]);
        RouteFare::create(['from_station_id' => $agnibilekrou->id, 'to_station_id' => $bondoukou->id, 'amount' => 1500, 'is_bidirectional' => true]);

        // ==========================================
        // VOYAGES (TRIPS) - Mix de seat_assignment et bulk
        // ==========================================
        $today = Carbon::today();

        // Voyages Abidjan → Korhogo (Bus 70 places)
        Trip::create([
            'route_id' => $routeAbidjanKorhogo->id,
            'vehicle_id' => $vehiclesBus70_3x2[0]->id,
            'departure_at' => $today->copy()->setTime(6, 0),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        Trip::create([
            'route_id' => $routeAbidjanKorhogo->id,
            'vehicle_id' => $vehiclesBus70_2x2[0]->id,
            'departure_at' => $today->copy()->setTime(10, 0),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        Trip::create([
            'route_id' => $routeAbidjanKorhogo->id,
            'vehicle_id' => $vehiclesBus70_3x2[1]->id,
            'departure_at' => $today->copy()->setTime(14, 0),
            'status' => 'scheduled',
            'booking_type' => 'bulk' // Mode en vrac
        ]);

        Trip::create([
            'route_id' => $routeAbidjanKorhogo->id,
            'vehicle_id' => $vehiclesBus70_2x2[0]->id,
            'departure_at' => $today->copy()->setTime(18, 0),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        // Voyages Abidjan → Bondoukou (Bus 50 places)
        Trip::create([
            'route_id' => $routeAbidjanBondoukou->id,
            'vehicle_id' => $vehiclesBus50_3x2[0]->id,
            'departure_at' => $today->copy()->setTime(7, 0),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        Trip::create([
            'route_id' => $routeAbidjanBondoukou->id,
            'vehicle_id' => $vehiclesBus50_2x2[0]->id,
            'departure_at' => $today->copy()->setTime(12, 0),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        Trip::create([
            'route_id' => $routeAbidjanBondoukou->id,
            'vehicle_id' => $vehiclesBus50_3x2[1]->id,
            'departure_at' => $today->copy()->setTime(16, 0),
            'status' => 'scheduled',
            'booking_type' => 'bulk' // Mode en vrac
        ]);

        // Voyages pour demain
        $tomorrow = Carbon::tomorrow();

        Trip::create([
            'route_id' => $routeAbidjanKorhogo->id,
            'vehicle_id' => $vehiclesDouble80[0]->id,
            'departure_at' => $tomorrow->copy()->setTime(6, 0),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        Trip::create([
            'route_id' => $routeAbidjanBondoukou->id,
            'vehicle_id' => $vehiclesBus50_2x2[0]->id,
            'departure_at' => $tomorrow->copy()->setTime(7, 30),
            'status' => 'scheduled',
            'booking_type' => 'seat_assignment'
        ]);

        $this->command->info('✅ Seeder terminé avec succès!');
        $this->command->info('📊 Statistiques:');
        $this->command->info('   - Utilisateurs: ' . User::count());
        $this->command->info('   - Stations: ' . Station::count());
        $this->command->info('   - Types de véhicules: ' . VehicleType::count());
        $this->command->info('   - Véhicules: ' . Vehicle::count());
        $this->command->info('   - Routes: ' . Route::count());
        $this->command->info('   - Voyages: ' . Trip::count());
    }
}
