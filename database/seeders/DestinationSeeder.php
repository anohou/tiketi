<?php

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Seeder;

class DestinationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $villesConfig = config('transport.villes', []);

        foreach ($villesConfig as $ville) {
            Destination::updateOrCreate(
                ['name' => $ville['name']],
                [
                    'city' => $ville['name'],
                    'region' => $ville['region'],
                    'is_active' => true,
                ]
            );
        }
    }
}
