<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ManagesVehicles;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetVehicleController extends Controller
{
    use ManagesVehicles;

    public function index()
    {
        $user = auth()->user();

        $query = Vehicle::with([
            'vehicleType',
            'trips.route',
            'managers:id,name,email,role',
            'currentCrew.crewMember',
        ])
            ->withCount('trips')
            ->orderBy('identifier');

        if ($user && $user->role === 'fleet_manager') {
            $query->whereHas('managers', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

        $vehicles = $query->paginate(50);

        $vehicleTypes = VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']);

        $crewMembers = \App\Models\CrewMember::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'phone', 'license_number']);

        return Inertia::render('Fleet/Vehicles/Index', [
            'vehicles' => $vehicles,
            'vehicleTypes' => $vehicleTypes,
            'crewMembers' => $crewMembers,
        ]);
    }

    public function create()
    {
        return Inertia::render('Fleet/Vehicles/Form', [
            'vehicleTypes' => VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']),
        ]);
    }

    public function store(Request $request)
    {
        $vehicle = $this->performStoreVehicle($request);

        $user = auth()->user();
        if ($user && $user->role === 'fleet_manager') {
            // Assigner automatiquement le véhicule créé au gestionnaire
            \App\Models\UserVehicleAssignment::create([
                'user_id' => $user->id,
                'vehicle_id' => $vehicle->id,
                'active' => true,
            ]);
        }

        return redirect()->route('fleet.vehicles.index')->with('success', 'Véhicule enregistré avec succès.');
    }

    public function edit(Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        return Inertia::render('Fleet/Vehicles/Form', [
            'vehicle' => $vehicle,
            'vehicleTypes' => VehicleType::orderBy('name')->get(['id', 'name', 'seat_count']),
        ]);
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        $this->performUpdateVehicle($request, $vehicle);

        return redirect()->route('fleet.vehicles.index')->with('success', 'Véhicule mis à jour.');
    }

    public function destroy(Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        $this->performDestroyVehicle($vehicle);

        return back()->with('success', 'Véhicule supprimé.');
    }

    public function toggleActive(Request $request, Vehicle $vehicle)
    {
        $this->authorizeVehicle($vehicle);

        $data = $request->validate([
            'active' => 'required|boolean',
            'inactive_reason' => 'nullable|string',
        ]);

        $vehicle->update([
            'active' => $data['active'],
            'inactive_reason' => $data['active'] ? null : ($data['inactive_reason'] ?? $vehicle->inactive_reason),
        ]);

        return back();
    }

    /**
     * Autoriser l'action sur le véhicule si l'utilisateur est gestionnaire de flotte
     */
    protected function authorizeVehicle(Vehicle $vehicle): void
    {
        $user = auth()->user();
        if ($user && $user->role === 'fleet_manager') {
            $isAssigned = $vehicle->managers()->where('users.id', $user->id)->exists();
            if (! $isAssigned) {
                abort(403, 'Vous n\'êtes pas autorisé à gérer ce véhicule.');
            }
        }
    }
}
