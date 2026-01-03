<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DestinationController extends Controller
{
    public function index(Request $request)
    {
        $destinations = Destination::query()
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->withCount('stations')
            ->with('stations')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Destinations/Index', [
            'destinations' => $destinations,
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:destinations,name',
            'city' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        Destination::create($validated);

        return back()->with('success', 'Destination créée avec succès.');
    }

    public function update(Request $request, Destination $destination)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:destinations,name,' . $destination->id,
            'city' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $destination->update($validated);

        return back()->with('success', 'Destination mise à jour avec succès.');
    }

    public function destroy(Destination $destination)
    {
        // Check for dependencies (Stations, Routes)
        if ($destination->stations()->exists()) {
            return back()->with('error', 'Impossible de supprimer cette destination car elle contient des gares.');
        }

        if ($destination->originRoutes()->exists() || $destination->targetRoutes()->exists()) {
             return back()->with('error', 'Impossible de supprimer cette destination car elle est liée à des routes.');
        }

        $destination->delete();

        return back()->with('success', 'Destination supprimée avec succès.');
    }
}
