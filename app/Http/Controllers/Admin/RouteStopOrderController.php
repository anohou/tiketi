<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\RouteStopOrder;
use App\Models\Stop;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RouteStopOrderController extends Controller
{
    /**
     * Display a listing of stops for a specific route.
     */
    public function index(Route $route)
    {
        $route->load(['routeStopOrders.stop.station']);
        
        // Get all stops to allow adding new ones
        $allStops = Stop::with('station')->orderBy('name')->get()->map(function ($stop) {
            return [
                'id' => $stop->id,
                'name' => $stop->name,
                'city' => $stop->station ? $stop->station->city : null,
            ];
        });

        return Inertia::render('Admin/Routes/Stops', [
            'routeModel' => $route,
            'stops' => $route->routeStopOrders->sortBy('stop_index')->values()->map(function ($order) {
                return [
                    'id' => $order->id,
                    'stop' => [
                        'id' => $order->stop->id,
                        'name' => $order->stop->name,
                        'city' => $order->stop->station ? $order->stop->station->city : null,
                    ],
                    'stop_index' => $order->stop_index,
                ];
            }),
            'availableStops' => $allStops
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Route $route)
    {
        $validated = $request->validate([
            'stop_id' => 'required|exists:stops,id',
            'stop_index' => 'required|integer|min:0',
        ]);

        // Check if stop already exists in this route
        if ($route->routeStopOrders()->where('stop_id', $validated['stop_id'])->exists()) {
            return back()->withErrors(['stop_id' => 'Cet arrêt est déjà présent sur cette route.']);
        }

        // Shift existing stops if inserting in middle
        $route->routeStopOrders()
            ->where('stop_index', '>=', $validated['stop_index'])
            ->increment('stop_index');

        $route->routeStopOrders()->create([
            'stop_id' => $validated['stop_id'],
            'stop_index' => $validated['stop_index']
        ]);

        return back()->with('success', 'Arrêt ajouté avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Route $route, RouteStopOrder $routeStopOrder)
    {
        $deletedIndex = $routeStopOrder->stop_index;
        $routeStopOrder->delete();

        // Reorder remaining stops
        $route->routeStopOrders()
            ->where('stop_index', '>', $deletedIndex)
            ->decrement('stop_index');

        return back()->with('success', 'Arrêt supprimé avec succès.');
    }

    /**
     * Reorder stops - swap two adjacent stops.
     */
    public function reorder(Request $request, Route $route)
    {
        $validated = $request->validate([
            'orders' => 'required|array|size:2',
            'orders.*.id' => 'required|exists:route_stop_orders,id',
            'orders.*.stop_index' => 'required|integer',
        ]);

        \DB::transaction(function () use ($validated) {
            // Get a temporary index that won't conflict
            $tempIndex = RouteStopOrder::max('stop_index') + 1;
            
            // Get the two stops being swapped
            $stop1 = RouteStopOrder::find($validated['orders'][0]['id']);
            $stop2 = RouteStopOrder::find($validated['orders'][1]['id']);
            
            // First move stop1 to temp to avoid unique constraint
            $stop1->update(['stop_index' => $tempIndex]);
            
            // Set stop2 to its new index
            $stop2->update(['stop_index' => $validated['orders'][1]['stop_index']]);
            
            // Set stop1 to its new index
            $stop1->update(['stop_index' => $validated['orders'][0]['stop_index']]);
        });

        return back()->with('success', 'Ordre mis à jour avec succès.');
    }
}
