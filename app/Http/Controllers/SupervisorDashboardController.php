<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SupervisorDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // 1. Get assigned stations
        $stationIds = $user->assignedStations()->pluck('stations.id');
        
        // 2. Fetch Live Feed: Departures from these stations (Next 24h)
        $departures = \App\Models\Trip::query()
            ->whereIn('origin_station_id', $stationIds)
            ->where('departure_at', '>=', now())
            ->where('departure_at', '<=', now()->addHours(24))
            ->with([
                'route:id,name', 
                'vehicle:id,license_plate,vehicle_type_id', 
                'vehicle.vehicleType:id,seat_count',
                'destinationStation:id,name',
                'originStation:id,name'
            ])
            ->orderBy('departure_at')
            ->get()
            ->map(function ($trip) {
                // Calculate occupancy percentage
                $total = $trip->total_seats;
                $sold = $total - $trip->available_seats;
                $percent = $total > 0 ? round(($sold / $total) * 100) : 0;
                
                return [
                    'id' => $trip->id,
                    'route_name' => $trip->display_name,
                    'destination' => $trip->destinationStation->name ?? '?',
                    'origin' => $trip->originStation->name ?? '?',
                    'departure_time' => $trip->departure_at->format('H:i'),
                    'departure_timestamp' => $trip->departure_at->timestamp,
                    'license_plate' => $trip->vehicle->license_plate ?? null,
                    'occupancy_percent' => $percent,
                    'available_seats' => $trip->available_seats,
                    'total_seats' => $total,
                    'status' => $trip->status,
                    'alert_level' => $this->calculateAlertLevel($trip, $percent)
                ];
            });

        // 3. Mock Validations (for now)
        $validations = [
            [
                'id' => 'val-1',
                'type' => 'cancellation',
                'ticket_number' => 'B50-3X2-005',
                'seller_name' => 'Guichet 2',
                'reason' => 'Erreur client (destination)',
                'time_ago' => '2 min',
                'trip_id' => $departures->first()['id'] ?? null // Link to real trip if exists
            ]
        ];

        // 4. Active Sellers Cash View (Mock)
        $sellers = \App\Models\User::where('role', 'seller')
            ->whereHas('assignedStations', function($q) use ($stationIds) {
                $q->whereIn('stations.id', $stationIds);
            })
            ->take(5)
            ->get()
            ->map(function($seller) {
                return [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'station' => $seller->assignedStations->first()->name ?? '?',
                    'cash_balance' => rand(50000, 500000), // Mock cash
                    'status' => 'online'
                ];
            });

        return Inertia::render('Dashboards/Supervisor', [
            'departures' => $departures,
            'validations' => $validations,
            'sellers' => $sellers,
            'user_stations' => $user->assignedStations->pluck('name'),
        ]);
    }

    private function calculateAlertLevel($trip, $percent)
    {
        // Logic: < 15 mins to departure and < 50% full = Critical
        $minsToDeparture = now()->diffInMinutes($trip->departure_at, false);
        
        if ($minsToDeparture < 15 && $percent < 50) return 'critical'; // Red
        if ($minsToDeparture < 30 && $percent < 80) return 'warning';  // Orange
        if (!$trip->vehicle_id) return 'warning'; // Missing vehicle
        
        return 'normal';
    }
}
