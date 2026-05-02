<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Data for Seeders
    |--------------------------------------------------------------------------
    |
    | This file contains the default data used to seed the application.
    | You can customize these values to match your production needs.
    |
    */

    'vehicle_types' => [
        [
            'name' => 'Massa (15 places)',
            'total_capacity' => 15,
            'door_count' => 1,
            'seat_configuration' => '2+1',
            'svg_template_path' => 'minibus_15',
        ],
        [
            'name' => 'Minicar 30 places',
            'total_capacity' => 30,
            'door_count' => 2,
            'seat_configuration' => '2+2',
            'svg_template_path' => 'bus_30',
        ],
        [
            'name' => 'Autocar 50 places',
            'total_capacity' => 50,
            'door_count' => 2,
            'seat_configuration' => '2+2',
            'svg_template_path' => 'bus_50',
        ],
        [
            'name' => 'Grand Car 70 places',
            'total_capacity' => 74, // Inclut les slots de portes
            'door_count' => 3,
            'seat_configuration' => '2+2',
            'svg_template_path' => 'bus_70',
        ],
    ],

    'villes' => [
        ['name' => 'Abidjan', 'region' => 'Lagunes'],
        ['name' => 'Yamoussoukro', 'region' => 'Lacs'],
        ['name' => 'Bouaké', 'region' => 'Gbêkê'],
        ['name' => 'Korhogo', 'region' => 'Poro'],
        ['name' => 'San-Pedro', 'region' => 'Bas-Sassandra'],
        ['name' => 'Man', 'region' => 'Tonkpi'],
    ],

    'gares_par_ville' => [
        'Abidjan' => [
            ['name' => 'Gare Nord (Adjamé)', 'code' => 'ABJ-NORD'],
            ['name' => 'Gare Sud (Treichville)', 'code' => 'ABJ-SUD'],
        ],
        'Yamoussoukro' => [
            ['name' => 'Gare Centrale', 'code' => 'YAK-CENTRE'],
        ],
        'Bouaké' => [
            ['name' => 'Gare de Bouaké', 'code' => 'BKE-MAIN'],
        ],
    ],

    'routes_par_defaut' => [
        [
            'name' => 'Abidjan ↔ Yamoussoukro',
            'origin' => 'ABJ-NORD',
            'destination' => 'YAK-CENTRE',
            'fare' => 5000,
        ],
        [
            'name' => 'Abidjan ↔ Bouaké',
            'origin' => 'ABJ-NORD',
            'destination' => 'BKE-MAIN',
            'fare' => 8000,
        ],
    ],
];
