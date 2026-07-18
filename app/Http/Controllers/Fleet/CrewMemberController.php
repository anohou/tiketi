<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\CrewMember;
use App\Models\VehicleCrewAssignment;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            'phone' => ['required', 'string', 'max:50'],
            'pin' => ['required', 'string', 'digits_between:6,12'],
            'role' => 'required|in:driver,assistant',
            'license_number' => 'nullable|string|max:100',
            'license_expiry_date' => 'nullable|date',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $data['phone'] = $this->canonicalPhone($data['phone']);
        $this->ensureUniquePhone($data['phone']);

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
            'phone' => ['required', 'string', 'max:50'],
            'pin' => ['nullable', 'string', 'digits_between:6,12'],
            'role' => 'required|in:driver,assistant',
            'license_number' => 'nullable|string|max:100',
            'license_expiry_date' => 'nullable|date',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $data['phone'] = $this->canonicalPhone($data['phone']);
        $this->ensureUniquePhone($data['phone'], $crewMember);

        $pinChanged = filled($data['pin'] ?? null);
        if (! $pinChanged) {
            unset($data['pin']);
        }

        $crewMember->update($data);

        if ($pinChanged || ! $crewMember->active) {
            $crewMember->tokens()->delete();
        }

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

    private function canonicalPhone(string $phone): string
    {
        return PhoneNumber::normalize($phone)
            ?? throw ValidationException::withMessages(['phone' => 'Le numéro de téléphone est invalide.']);
    }

    private function ensureUniquePhone(string $phone, ?CrewMember $ignored = null): void
    {
        $duplicate = CrewMember::query()
            ->whereNotNull('phone')
            ->when($ignored, fn ($query) => $query->where($ignored->getKeyName(), '!=', $ignored->getKey()))
            ->get(['id', 'phone'])
            ->contains(fn (CrewMember $member) => PhoneNumber::normalize($member->phone) === $phone);

        if ($duplicate) {
            throw ValidationException::withMessages(['phone' => 'Ce numéro est déjà attribué à un membre d’équipage.']);
        }
    }
}
