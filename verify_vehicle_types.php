<?php

use App\Models\Tenant;
use App\Models\VehicleType;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = Tenant::find('test');
$tenant->run(function () {
    $types = VehicleType::all();
    foreach ($types as $type) {
        echo 'Name: '.$type->name."\n";
        echo 'Seat Count: '.$type->seat_count."\n";
        echo 'Door Positions: '.json_encode($type->door_positions)."\n";
        echo 'Last Row Seats: '.$type->last_row_seats."\n";
        echo "-------------------\n";
    }
});
