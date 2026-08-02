<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\CrewMember;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VehicleCrewAssignmentController extends Controller
{
    /**
     * Display a listing of crew assignments.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'vehicle_id' => ['nullable', 'uuid'],
            'crew_member_id' => ['nullable', 'uuid'],
            'role' => ['nullable', 'in:driver,assistant'],
            'status' => ['nullable', 'in:active,closed'],
        ]);

        $assignments = VehicleCrewAssignment::query()
            ->with(['vehicle.vehicleType', 'crewMember'])
            ->when($filters['vehicle_id'] ?? null, fn (Builder $query, string $vehicleId) => $query->where('vehicle_id', $vehicleId))
            ->when($filters['crew_member_id'] ?? null, fn (Builder $query, string $crewMemberId) => $query->where('crew_member_id', $crewMemberId))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->whereNull('assigned_to'))
            ->when(($filters['status'] ?? null) === 'closed', fn (Builder $query) => $query->whereNotNull('assigned_to'))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.trim($search).'%';
                $query->where(function (Builder $searchQuery) use ($term) {
                    $searchQuery
                        ->whereLike('notes', $term, caseSensitive: false)
                        ->orWhereHas('crewMember', fn (Builder $member) => $member
                            ->whereLike('name', $term, caseSensitive: false)
                            ->orWhereLike('phone', $term, caseSensitive: false)
                            ->orWhereLike('license_number', $term, caseSensitive: false))
                        ->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle
                            ->whereLike('identifier', $term, caseSensitive: false)
                            ->orWhereLike('maker', $term, caseSensitive: false)
                            ->orWhereHas('vehicleType', fn (Builder $type) => $type->whereLike('name', $term, caseSensitive: false)));
                });
            })
            ->orderByDesc('assigned_from')
            ->paginate(40)
            ->withQueryString();

        $crewMembers = CrewMember::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'phone', 'license_number']);

        $vehicles = Vehicle::with('vehicleType')
            ->where('active', true)
            ->orderBy('identifier')
            ->get(['id', 'identifier', 'maker', 'vehicle_type_id']);

        return Inertia::render('Fleet/CrewAssignments/Index', [
            'assignments' => $assignments,
            'crewMembers' => $crewMembers,
            'vehicles' => $vehicles,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'vehicle_id' => $filters['vehicle_id'] ?? '',
                'crew_member_id' => $filters['crew_member_id'] ?? '',
                'role' => $filters['role'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    /**
     * Store a newly created crew assignment.
     * Automatically closes the previous assignment of the same role on the same vehicle.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'crew_member_id' => 'required|uuid|exists:crew_members,id',
            'role' => 'required|in:driver,assistant',
            'assigned_from' => 'required|date',
            'assigned_to' => 'nullable|date|after:assigned_from',
            'notes' => 'nullable|string|max:1000',
        ]);

        $crewMember = CrewMember::findOrFail($data['crew_member_id']);
        if (! $crewMember->active) {
            return back()->withErrors(['crew_member_id' => 'Ce membre d’équipage est inactif.']);
        }
        if ($crewMember->role !== $data['role']) {
            return back()->withErrors([
                'crew_member_id' => 'Ce membre d\'équipage est un '
                    .($crewMember->role === 'driver' ? 'chauffeur' : 'assistant')
                    .', pas un '
                    .($data['role'] === 'driver' ? 'chauffeur' : 'assistant').'.',
            ]);
        }

        if ($crewMember->isDriver() && $crewMember->isLicenseExpired($data['assigned_from'])) {
            return back()->withErrors([
                'crew_member_id' => 'Le permis de conduire de ce chauffeur est expiré à la date de début d\'affectation ('
                    .$crewMember->license_expiry_date->format('d/m/Y').').',
            ]);
        }

        $newStart = Carbon::parse($data['assigned_from']);
        $newEnd = isset($data['assigned_to']) ? Carbon::parse($data['assigned_to']) : null;

        // A conflict is any assignment starting at or after $newStart that overlaps with [newStart, newEnd)
        $overlapQuery = fn ($query) => $query
            ->where('assigned_from', '>=', $newStart)
            ->when($newEnd, fn ($q) => $q->where('assigned_from', '<', $newEnd));

        $vehicleConflict = $overlapQuery(VehicleCrewAssignment::query()
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('role', $data['role']))
            ->exists();

        if ($vehicleConflict) {
            return back()->withErrors([
                'assigned_from' => 'Cette période chevauche une affectation future pour ce rôle sur ce véhicule.',
            ]);
        }

        $crewConflict = $overlapQuery(VehicleCrewAssignment::query()
            ->where('crew_member_id', $data['crew_member_id']))
            ->exists();

        if ($crewConflict) {
            return back()->withErrors([
                'assigned_from' => 'Cette période chevauche une affectation future pour ce membre d\'équipage.',
            ]);
        }

        DB::transaction(function () use ($data, $newStart) {
            // Clôturer l'affectation précédente du même rôle sur ce véhicule
            VehicleCrewAssignment::where('vehicle_id', $data['vehicle_id'])
                ->where('role', $data['role'])
                ->where('assigned_from', '<', $newStart)
                ->where(fn ($query) => $query->whereNull('assigned_to')->orWhere('assigned_to', '>', $newStart))
                ->update(['assigned_to' => $newStart]);

            // Clôturer aussi l'affectation active de ce crew member sur un autre véhicule
            VehicleCrewAssignment::where('crew_member_id', $data['crew_member_id'])
                ->where('assigned_from', '<', $newStart)
                ->where(fn ($query) => $query->whereNull('assigned_to')->orWhere('assigned_to', '>', $newStart))
                ->update(['assigned_to' => $newStart]);

            VehicleCrewAssignment::create($data);
        });

        return back()->with('success', 'Affectation créée avec succès.');
    }

    /**
     * Update the specified crew assignment.
     */
    public function update(Request $request, VehicleCrewAssignment $crewAssignment)
    {
        $data = $request->validate([
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'crew_member_id' => 'required|uuid|exists:crew_members,id',
            'role' => 'required|in:driver,assistant',
            'assigned_from' => 'required|date',
            'assigned_to' => 'nullable|date|after:assigned_from',
            'notes' => 'nullable|string|max:1000',
        ]);

        $crewMember = CrewMember::findOrFail($data['crew_member_id']);
        if (! $crewMember->active) {
            return back()->withErrors(['crew_member_id' => 'Ce membre d’équipage est inactif.']);
        }
        if ($crewMember->role !== $data['role']) {
            return back()->withErrors(['crew_member_id' => 'Le rôle de l’affectation ne correspond pas au rôle du membre d’équipage.']);
        }
        if ($crewMember->isDriver() && $crewMember->isLicenseExpired($data['assigned_from'])) {
            return back()->withErrors(['crew_member_id' => 'Le permis de conduire est expiré à la date de début d’affectation.']);
        }

        $newStart = Carbon::parse($data['assigned_from']);
        $newEnd = isset($data['assigned_to']) ? Carbon::parse($data['assigned_to']) : null;

        $vehicleConflict = VehicleCrewAssignment::query()
            ->where($crewAssignment->getKeyName(), '!=', $crewAssignment->getKey())
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('role', $data['role'])
            ->overlapping($newStart, $newEnd)
            ->exists();
        if ($vehicleConflict) {
            return back()->withErrors(['assigned_from' => 'Cette période chevauche une autre affectation du même rôle sur ce véhicule.']);
        }

        $crewConflict = VehicleCrewAssignment::query()
            ->where($crewAssignment->getKeyName(), '!=', $crewAssignment->getKey())
            ->where('crew_member_id', $data['crew_member_id'])
            ->overlapping($newStart, $newEnd)
            ->exists();
        if ($crewConflict) {
            return back()->withErrors(['assigned_from' => 'Cette période chevauche une autre affectation de ce membre d’équipage.']);
        }

        $crewAssignment->update($data);

        return back()->with('success', 'Affectation mise à jour.');
    }

    /**
     * Close (end) a crew assignment by setting assigned_to to now.
     */
    public function destroy(VehicleCrewAssignment $crewAssignment)
    {
        if ($crewAssignment->assigned_to === null) {
            // Clôturer plutôt que supprimer pour garder l'historique
            $crewAssignment->update(['assigned_to' => now()]);

            return back()->with('success', 'Affectation clôturée.');
        }

        // Si déjà clôturée, supprimer définitivement
        $crewAssignment->delete();

        return back()->with('success', 'Affectation supprimée.');
    }
}
