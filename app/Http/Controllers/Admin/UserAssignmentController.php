<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use App\Models\UserStationAssignment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserAssignmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $assignments = UserStationAssignment::with(['user', 'station'])->orderBy('created_at', 'desc')->paginate(20);
        $users = User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name', 'email', 'role']);
        $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'city']);

        return Inertia::render('Admin/Assignments/Index', [
            'assignments' => $assignments,
            'users' => $users,
            'stations' => $stations,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Assignments/Form', [
            'users' => User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'stations' => Station::where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'city']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'station_id' => 'required|uuid|exists:stations,id',
            'active' => 'boolean',
        ]);

        // Check if assignment already exists
        $existing = UserStationAssignment::where('user_id', $data['user_id'])
            ->where('station_id', $data['station_id'])
            ->first();

        if ($existing) {
            return back()->withErrors(['station_id' => 'Cet utilisateur est déjà affecté à cette gare.']);
        }

        UserStationAssignment::create($data);

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(UserStationAssignment $assignment)
    {
        return redirect()->route('admin.assignments.edit', $assignment);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UserStationAssignment $assignment)
    {
        return Inertia::render('Admin/Assignments/Form', [
            'assignment' => $assignment->load(['user', 'station']),
            'users' => User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name', 'email', 'role']),
            'stations' => Station::where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'city']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UserStationAssignment $assignment)
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'station_id' => 'required|uuid|exists:stations,id',
            'active' => 'boolean',
        ]);

        // Check if assignment already exists (excluding current)
        $existing = UserStationAssignment::where('user_id', $data['user_id'])
            ->where('station_id', $data['station_id'])
            ->where('id', '!=', $assignment->id)
            ->first();

        if ($existing) {
            return back()->withErrors(['station_id' => 'Cet utilisateur est déjà affecté à cette gare.']);
        }

        $assignment->update($data);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UserStationAssignment $assignment)
    {
        $assignment->delete();

        return back();
    }
}
