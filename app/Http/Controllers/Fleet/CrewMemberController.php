<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\CrewMember;
use App\Models\VehicleCrewAssignment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CrewMemberController extends Controller
{
    /**
     * Display a listing of crew members.
     */
    public function index()
    {
        $crewMembers = CrewMember::with(['currentAssignment.vehicle'])
            ->withCount(['vehicleAssignments'])
            ->orderBy('name')
            ->paginate(30);

        return Inertia::render('Fleet/Crew/Index', [
            'crewMembers' => $crewMembers,
        ]);
    }

    /**
     * Store a newly created crew member.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|in:driver,assistant',
            'license_number' => 'nullable|string|max:100',
            'license_expiry_date' => 'nullable|date',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        CrewMember::create($data);

        return back()->with('success', 'Membre d\'équipage créé avec succès.');
    }

    /**
     * Update the specified crew member.
     */
    public function update(Request $request, CrewMember $crewMember)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'role' => 'required|in:driver,assistant',
            'license_number' => 'nullable|string|max:100',
            'license_expiry_date' => 'nullable|date',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $crewMember->update($data);

        return back()->with('success', 'Membre d\'équipage mis à jour.');
    }

    /**
     * Remove the specified crew member.
     */
    public function destroy(CrewMember $crewMember)
    {
        // Clôturer toutes les affectations actives avant la suppression
        VehicleCrewAssignment::where('crew_member_id', $crewMember->id)
            ->active()
            ->update(['assigned_to' => now()]);

        $crewMember->delete();

        return back()->with('success', 'Membre d\'équipage supprimé.');
    }
}
