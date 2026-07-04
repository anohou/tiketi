<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserVehicleAssignment;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetAssignmentController extends Controller
{
    public function index()
    {
        $assignments = UserVehicleAssignment::with(['user', 'vehicle.vehicleType'])
            ->orderByDesc('created_at')
            ->paginate(30);

        return Inertia::render('Fleet/Assignments/Index', [
            'assignments' => $assignments,
            'users' => User::where('role', 'fleet_manager')->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'vehicles' => Vehicle::with('vehicleType')->orderBy('identifier')->get(['id', 'identifier', 'maker', 'vehicle_type_id', 'active']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'active' => 'boolean',
        ]);

        $existing = UserVehicleAssignment::where('user_id', $data['user_id'])
            ->where('vehicle_id', $data['vehicle_id'])
            ->first();

        if ($existing) {
            return back()->withErrors(['vehicle_id' => 'Ce véhicule est déjà assigné à ce gestionnaire.']);
        }

        UserVehicleAssignment::create($data);

        return back()->with('success', 'Véhicule assigné.');
    }

    public function update(Request $request, UserVehicleAssignment $assignment)
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'active' => 'boolean',
        ]);

        $existing = UserVehicleAssignment::where('user_id', $data['user_id'])
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('id', '!=', $assignment->id)
            ->first();

        if ($existing) {
            return back()->withErrors(['vehicle_id' => 'Ce véhicule est déjà assigné à ce gestionnaire.']);
        }

        $assignment->update($data);

        return back()->with('success', 'Affectation mise à jour.');
    }

    public function destroy(UserVehicleAssignment $assignment)
    {
        $assignment->delete();

        return back()->with('success', 'Affectation supprimée.');
    }
}
