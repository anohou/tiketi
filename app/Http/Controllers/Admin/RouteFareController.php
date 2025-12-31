<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RouteFare;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouteFareController extends Controller
{
    public function index()
    {
        $fares = RouteFare::with(['fromStation', 'toStation'])
            ->latest()
            ->get();

        $stations = Station::all()->map(function ($station) {
            return [
                'id' => $station->id,
                'name' => $station->name,
                'city' => $station->city,
            ];
        });

        return Inertia::render('Admin/RouteFares/Index', [
            'fares' => $fares,
            'stations' => $stations,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_station_id' => 'required|exists:stations,id',
            'to_station_id' => 'required|exists:stations,id|different:from_station_id',
            'amount' => 'required|integer|min:0',
            'is_bidirectional' => 'boolean',
        ]);

        // Check for duplicate (direct or reverse if bidirectional)
        $exists = RouteFare::where('from_station_id', $request->from_station_id)
            ->where('to_station_id', $request->to_station_id)
            ->exists();

        // Also check reverse direction
        $reverseExists = RouteFare::where('from_station_id', $request->to_station_id)
            ->where('to_station_id', $request->from_station_id)
            ->exists();

        if ($exists || $reverseExists) {
            return back()->withErrors(['from_station_id' => 'Ce tarif existe déjà pour ce trajet (ou son inverse).']);
        }

        RouteFare::create($validated);

        return redirect()->back()->with('success', 'Tarif créé avec succès');
    }

    public function update(Request $request, $id)
    {
        $routeFare = RouteFare::findOrFail($id);

        $validated = $request->validate([
            'from_station_id' => 'required|exists:stations,id',
            'to_station_id' => 'required|exists:stations,id|different:from_station_id',
            'amount' => 'required|integer|min:0',
            'is_bidirectional' => 'boolean',
        ]);

        // Check for duplicate excluding current (direct)
        $exists = RouteFare::where('from_station_id', $request->from_station_id)
            ->where('to_station_id', $request->to_station_id)
            ->where('id', '!=', $routeFare->id)
            ->exists();

        // Also check reverse direction excluding current
        $reverseExists = RouteFare::where('from_station_id', $request->to_station_id)
            ->where('to_station_id', $request->from_station_id)
            ->where('id', '!=', $routeFare->id)
            ->exists();

        if ($exists || $reverseExists) {
            return back()->withErrors(['from_station_id' => 'Ce tarif existe déjà pour ce trajet (ou son inverse).']);
        }

        $routeFare->update($validated);

        return redirect()->back()->with('success', 'Tarif mis à jour avec succès');
    }

    public function destroy($id)
    {
        $routeFare = RouteFare::findOrFail($id);
        $routeFare->delete();

        return redirect()->back()->with('success', 'Tarif supprimé avec succès');
    }
}
