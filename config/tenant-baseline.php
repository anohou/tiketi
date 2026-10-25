<?php

return [
    /*
     * Semantic fingerprint of a clean PostgreSQL database created from the
     * immutable consolidated tenant baseline. This deliberately ignores
     * physical column order and database object names, but includes tables,
     * columns, defaults, constraints, foreign-key actions, and index behavior.
     */
    'schema_fingerprint' => '143e9645ac0927146efad8d11490dfa6a3d693d94faf7f21f8686d0c65891cb4',

    /*
     * Historical migrations that prove a database followed the pre-baseline
     * migration chain. All markers must be present before adoption is allowed.
     */
    'legacy_markers' => [
        '2025_08_08_191607_create_stations_table',
        '2026_07_24_090000_create_authorized_devices_table',
        '2026_08_10_000001_drop_round_trip_fares_table',
    ],

    'migration_path' => database_path('migrations/tenant'),
    'lock_key' => 'tiketi:tenant-baseline:v1',
];
