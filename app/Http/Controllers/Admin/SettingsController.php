<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Route;

use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\Trip;
use App\Models\RouteFare;
use App\Models\User;
use App\Models\UserStationAssignment;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Settings/Index', [
            'stats' => [
                'stations' => Station::count(),
                'destinations' => \App\Models\Destination::count(),
                'routes' => Route::count(),

                'vehicles' => Vehicle::count(),
                'vehicleTypes' => VehicleType::count(),
                'trips' => Trip::count(),
                'fares' => RouteFare::count(),
                'users' => User::count(),
                'assignments' => UserStationAssignment::count(),
            ]
        ]);
    }
}
