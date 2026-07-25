<?php

return [
    'boarding' => [
        'future_tolerance_minutes' => env('BOARDING_FUTURE_TOLERANCE_MINUTES', 5),
        'past_window_hours' => env('BOARDING_PAST_WINDOW_HOURS', 24),
    ],
    'operations' => [
        'day_start_hour' => env('OPERATIONAL_DAY_START_HOUR', 3),
        'active_trip_lookback_hours' => env('ACTIVE_TRIP_LOOKBACK_HOURS', 48),
        'scheduled_lookahead_hours' => env('SCHEDULED_TRIP_LOOKAHEAD_HOURS', 30),
    ],
    'crew_auth' => [
        'default_country_code' => env('CREW_DEFAULT_COUNTRY_CODE', '225'),
        'max_login_attempts' => env('CREW_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_seconds' => env('CREW_LOGIN_LOCKOUT_SECONDS', 60),
        'login_backoff_base_seconds' => env('CREW_LOGIN_BACKOFF_BASE_SECONDS', 2),
        'login_backoff_max_seconds' => env('CREW_LOGIN_BACKOFF_MAX_SECONDS', 60),
        'token_expiration_days' => env('CREW_TOKEN_EXPIRATION_DAYS', 30),
        'token_inactivity_days' => env('CREW_TOKEN_INACTIVITY_DAYS', 14),
    ],
    'offline' => [
        'confirmed_retention_days' => env('OFFLINE_CONFIRMED_RETENTION_DAYS', 7),
        'rejected_retention_days' => env('OFFLINE_REJECTED_RETENTION_DAYS', 30),
        'ticket_cache_ttl_minutes' => env('OFFLINE_TICKET_CACHE_TTL_MINUTES', 360),
        'signing_private_key' => env('OFFLINE_CACHE_SIGNING_PRIVATE_KEY'),
    ],
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
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+1',
            'svg_template_path' => 'minibus_15',
        ],
        // 30 Places
        [
            'name' => 'Minicar 30 places (2+2)',
            'total_capacity' => 34,
            'door_count' => 2,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+2',
            'svg_template_path' => 'bus_30',
        ],
        [
            'name' => 'Minicar 30 places (3+2)',
            'total_capacity' => 32,
            'door_count' => 2,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '3+2',
            'svg_template_path' => 'bus_30',
        ],
        // 50 Places
        [
            'name' => 'Autocar 50 places (2+2)',
            'total_capacity' => 54,
            'door_count' => 3,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+2',
            'seat_count' => 52,
            'door_positions' => [0, 35, 36],
            'last_row_seats' => 6,
            'svg_template_path' => 'bus_50_2x2',
        ],
        [
            'name' => 'Autocar 50 places (3+2)',
            'total_capacity' => 52,
            'door_count' => 3,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '3+2',
            'seat_count' => 50,
            'door_positions' => [0, 34, 35],
            'last_row_seats' => 6,
            'svg_template_path' => 'bus_50_3x2',
        ],
        // 70 Places
        [
            'name' => 'Grand Car 70 places (2+2)',
            'total_capacity' => 74,
            'door_count' => 3,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '2+2',
            'svg_template_path' => 'bus_70',
        ],
        [
            'name' => 'Grand Car 70 places (3+2)',
            'total_capacity' => 72,
            'door_count' => 3,
            'door_side' => 'right',
            'door_width' => 2,
            'seat_configuration' => '3+2',
            'svg_template_path' => 'bus_70',
        ],
    ],

    'production_vehicle_types' => [
        [
            'name' => 'Autocar 50 places (3+2)',
            'seat_count' => 50,
            'seat_configuration' => '3+2',
            'door_count' => 3,
            'door_positions' => [0, 34, 35],
            'door_side' => 'right',
            'door_width' => 2,
            'last_row_seats' => 6,
            'svg_template_path' => 'bus_50_3x2',
            'active' => true,
        ],
        [
            'name' => 'Autocar 50 places (2+2)',
            'seat_count' => 52,
            'seat_configuration' => '2+2',
            'door_count' => 3,
            'door_positions' => [0, 35, 36],
            'door_side' => 'right',
            'door_width' => 2,
            'last_row_seats' => 6,
            'svg_template_path' => 'bus_50_2x2',
            'active' => true,
        ],
    ],

    'villes' => [
        ['name' => 'Abidjan', 'region' => 'Lagunes'],
        ['name' => 'Divo', 'region' => 'Lôh-Djiboua'],
        ['name' => 'Gagnoa', 'region' => 'Gôh'],
        ['name' => 'Yamoussoukro', 'region' => 'Lacs'],
        ['name' => 'Bouaké', 'region' => 'Gbêkê'],
        ['name' => 'Katiola', 'region' => 'Hambol'],
        ['name' => 'Korhogo', 'region' => 'Poro'],
    ],

    'gares_par_ville' => [
        'Abidjan' => [
            ['name' => 'Gare Nord (Adjamé)', 'code' => 'ABJ-NORD'],
            ['name' => 'Gare Sud (Treichville)', 'code' => 'ABJ-SUD'],
        ],
        'Yamoussoukro' => [
            ['name' => 'Gare de Yamoussoukro', 'code' => 'YAK-CENTRE'],
        ],
        'Divo' => [
            ['name' => 'Gare de Divo', 'code' => 'DIV-MAIN'],
        ],
        'Gagnoa' => [
            ['name' => 'Gare de Gagnoa', 'code' => 'GAG-MAIN'],
        ],
        'Bouaké' => [
            ['name' => 'Gare de Bouaké', 'code' => 'BKE-MAIN'],
        ],
        'Katiola' => [
            ['name' => 'Gare de Katiola', 'code' => 'KAT-MAIN'],
        ],
        'Korhogo' => [
            ['name' => 'Gare de Korhogo', 'code' => 'KGO-MAIN'],
        ],
    ],

    'routes_par_defaut' => [
        [
            'name' => 'Abidjan ↔ Gagnoa via Yamoussoukro et Divo',
            'origin' => 'ABJ-NORD',
            'destination' => 'GAG-MAIN',
            'stops' => ['ABJ-NORD', 'YAK-CENTRE', 'DIV-MAIN', 'GAG-MAIN'],
            'fare' => 7500,
            'estimated_duration_minutes' => 270,
            'automatic_connection_allocation' => true,
        ],
        [
            'name' => 'Abidjan ↔ Korhogo',
            'origin' => 'ABJ-NORD',
            'destination' => 'KGO-MAIN',
            'stops' => ['ABJ-NORD', 'YAK-CENTRE', 'BKE-MAIN', 'KAT-MAIN', 'KGO-MAIN'],
            'fare' => 12000,
            'estimated_duration_minutes' => 600,
            'automatic_connection_allocation' => true,
        ],
    ],

];
