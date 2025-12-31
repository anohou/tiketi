<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $stations = Station::with([
            'destination', // Eager load destination
            'userAssignments.user',
            'originRoutes.destinationStation', // Note: Route relations changed, might need update here if routes no longer have stations directly or if we want to show Cities
            'originRoutes.originStation',
            // ... (rest of eager loads might fail if relations changed on Route model, let's simplify for now)
        ])
        ->withCount(['userAssignments'])
        ->orderBy('name')
        ->paginate(50);
        
        $destinations = \App\Models\Destination::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/Stations/Index', [
            'stations' => $stations,
            'destinations' => $destinations,
        ]);
    }

    // ... create() ...

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string|unique:stations,code',
            'destination_id' => 'required|exists:destinations,id',
            'city' => 'nullable|string', // Legacy? Or keeps for detailed address?
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'active' => 'boolean',
        ]);
        
        // Auto-fill city name from destination if empty?
        if (empty($data['city'])) {
            $dest = \App\Models\Destination::find($data['destination_id']);
            $data['city'] = $dest->name;
        }

        Station::create($data);
        return back()->with('success', 'Gare créée avec succès.'); // Redirect back better for modals
    }

    // ... edit() ...

    public function update(Request $request, Station $station)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string|unique:stations,code,'.$station->id.',id',
            'destination_id' => 'required|exists:destinations,id',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'active' => 'boolean',
        ]);
        
        if (empty($data['city'])) {
            $dest = \App\Models\Destination::find($data['destination_id']);
            $data['city'] = $dest->name;
        }

        $station->update($data);
        return back()->with('success', 'Gare mise à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Station $station)
    {
        $station->delete();
        return back();
    }
}
