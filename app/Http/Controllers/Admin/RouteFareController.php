<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationalSetting;
use App\Models\RouteFare;
use App\Models\Station;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouteFareController extends Controller
{
    public function index()
    {
        $fares = RouteFare::with(['fromStation', 'toStation'])
            ->orderBy('from_station_id')
            ->orderBy('to_station_id')
            ->get();

        $stations = Station::orderBy('name')->get()->map(function ($station) {
            return [
                'id' => $station->id,
                'name' => $station->name,
                'city' => $station->city,
                'active' => (bool) $station->active,
            ];
        });

        return Inertia::render('Admin/RouteFares/Index', [
            'fares' => $fares,
            'stations' => $stations,
            'roundTripDiscount' => OperationalSetting::current()->roundTripDiscountAmount(),
            'roundTripSalesEnabled' => tenant()?->roundTripSalesEnabled() ?? true,
        ]);
    }

    /**
     * Remise globale aller-retour (montant fixe en FCFA). La grille des
     * tarifs gère les prix par trajet ; cette remise est appliquée au total
     * normal (aller + retour) de n'importe quel trajet.
     */
    public function updateRoundTripDiscount(Request $request)
    {
        $validated = $request->validate([
            'round_trip_discount_amount' => 'required|integer|min:0|max:1000000',
        ]);

        OperationalSetting::current()->setRoundTripDiscountAmount($validated['round_trip_discount_amount']);

        return redirect()->back()->with('success', 'Remise aller-retour mise à jour avec succès');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_station_id' => 'required|exists:stations,id',
            'to_station_id' => 'required|exists:stations,id|different:from_station_id',
            'amount' => 'required|integer|min:0',
            'is_bidirectional' => 'boolean',
            'active' => 'sometimes|boolean',
        ]);

        // A direct duplicate is always forbidden. The reverse direction may
        // coexist only when both fares are explicitly one-way.
        $exists = RouteFare::where('from_station_id', $request->from_station_id)
            ->where('to_station_id', $request->to_station_id)
            ->exists();

        $reverseFare = RouteFare::where('from_station_id', $request->to_station_id)
            ->where('to_station_id', $request->from_station_id)
            ->first();
        $reverseExists = $reverseFare
            && ((bool) ($validated['is_bidirectional'] ?? false) || $reverseFare->is_bidirectional);

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
            'active' => 'sometimes|boolean',
        ]);

        // Check for duplicate excluding current (direct).
        $exists = RouteFare::where('from_station_id', $request->from_station_id)
            ->where('to_station_id', $request->to_station_id)
            ->where('id', '!=', $routeFare->id)
            ->exists();

        $reverseFare = RouteFare::where('from_station_id', $request->to_station_id)
            ->where('to_station_id', $request->from_station_id)
            ->where('id', '!=', $routeFare->id)
            ->first();
        $reverseExists = $reverseFare
            && ((bool) ($validated['is_bidirectional'] ?? false) || $reverseFare->is_bidirectional);

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
