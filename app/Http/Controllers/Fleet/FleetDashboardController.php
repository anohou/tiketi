<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserVehicleAssignment;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Inertia\Inertia;

class FleetDashboardController extends Controller
{
    public function index()
    {
        $totalVehicles = Vehicle::count();
        $activeVehicles = Vehicle::where('active', true)->where('is_placeholder', false)->count();
        $inactiveVehicles = max(0, $totalVehicles - $activeVehicles);
        $vehicleTypes = VehicleType::count();
        $fleetManagers = User::where('role', 'fleet_manager')->count();
        $assignedVehicles = UserVehicleAssignment::where('active', true)->distinct('vehicle_id')->count('vehicle_id');

        $recentAssignments = UserVehicleAssignment::with(['user:id,name,email,role', 'vehicle:id,identifier,maker,active'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(function ($assignment) {
                return [
                    'id' => $assignment->id,
                    'user_name' => $assignment->user?->name,
                    'user_email' => $assignment->user?->email,
                    'vehicle_identifier' => $assignment->vehicle?->identifier,
                    'vehicle_maker' => $assignment->vehicle?->maker,
                    'vehicle_active' => $assignment->vehicle?->active,
                    'active' => $assignment->active,
                    'created_at' => $assignment->created_at?->toDateTimeString(),
                ];
            });

        $managerCoverage = User::where('role', 'fleet_manager')
            ->withCount(['vehicleAssignments as active_vehicle_assignments' => function ($query) {
                $query->where('active', true);
            }])
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active_vehicle_assignments' => $user->active_vehicle_assignments,
            ]);

        return Inertia::render('Dashboards/Fleet', [
            'stats' => [
                'totalVehicles' => $totalVehicles,
                'activeVehicles' => $activeVehicles,
                'inactiveVehicles' => $inactiveVehicles,
                'vehicleTypes' => $vehicleTypes,
                'fleetManagers' => $fleetManagers,
                'assignedVehicles' => $assignedVehicles,
            ],
            'recentAssignments' => $recentAssignments,
            'managerCoverage' => $managerCoverage,
        ]);
    }
}
