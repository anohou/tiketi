<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\RouteStopOrder;
use App\Models\Station;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
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

        // 2. Create Admin User
        $admin = User::updateOrCreate(
            ['email' => 'admin@transport.ci'],
            [
                'name' => 'Administrateur',
                'telephone' => '+225 0701234567',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'active' => true,
            ]
        );

        // 3. Seed Villes (Destinations)
        $this->call(DestinationSeeder::class);

        // 4. Seed Gares (Stations)
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

        // 5. Seed Routes and Fares
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
                    'active' => true,
                ]
            );

            // Set up stop orders (Origin and Destination)
            RouteStopOrder::updateOrCreate(
                ['route_id' => $route->id, 'stop_index' => 0],
                ['station_id' => $origin->id]
            );
            RouteStopOrder::updateOrCreate(
                ['route_id' => $route->id, 'stop_index' => 1],
                ['station_id' => $dest->id]
            );

            // Set up Fare
            RouteFare::updateOrCreate(
                [
                    'from_station_id' => $origin->id,
                    'to_station_id' => $dest->id,
                ],
                [
                    'amount' => $routeData['fare'],
                    'is_bidirectional' => true,
                    'active' => true,
                ]
            );
        }

        // 6. Seed a sample Vehicle if none exists
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
            }
        }

        // 7. Seed a sample Trip if none exists
        if (Trip::count() === 0 && Route::count() > 0 && Vehicle::count() > 0) {
            Trip::create([
                'route_id' => Route::first()->id,
                'vehicle_id' => Vehicle::first()->id,
                'departure_at' => Carbon::today()->setTime(8, 0),
                'status' => 'scheduled',
                'booking_type' => 'seat_assignment',
            ]);
        }
    }
}
