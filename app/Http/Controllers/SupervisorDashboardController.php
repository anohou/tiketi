<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Trip;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;

class SupervisorDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // 1. Get assigned stations
        $stationIds = $user->assignedStations()->pluck('stations.id');
        
        // 2. Today's Statistics
        $todayStart = Carbon::today();
        $todayEnd = Carbon::today()->endOfDay();
        
        $todayStats = [
            'total_revenue' => Ticket::whereHas('trip', function($q) use ($stationIds) {
                $q->whereIn('origin_station_id', $stationIds);
            })->whereBetween('created_at', [$todayStart, $todayEnd])->sum('price'),
            
            'tickets_sold' => Ticket::whereHas('trip', function($q) use ($stationIds) {
                $q->whereIn('origin_station_id', $stationIds);
            })->whereBetween('created_at', [$todayStart, $todayEnd])->count(),
            
            'trips_today' => Trip::whereIn('origin_station_id', $stationIds)
                ->whereBetween('departure_at', [$todayStart, $todayEnd])->count(),
                
            'trips_departed' => Trip::whereIn('origin_station_id', $stationIds)
                ->whereBetween('departure_at', [$todayStart, $todayEnd])
                ->where('status', 'departed')->count(),
        ];
        
        // 3. Fetch Live Feed: Departures from these stations (Next 24h)
        $departures = Trip::query()
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
                $minsToDeparture = now()->diffInMinutes($trip->departure_at, false);
                
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
                    'sold_seats' => $sold,
                    'status' => $trip->status,
                    'mins_to_departure' => max(0, $minsToDeparture),
                    'alert_level' => $this->calculateAlertLevel($trip, $percent),
                    'needs_vehicle' => !$trip->vehicle_id,
                ];
            });

        // 4. Collect Alerts
        $alerts = collect();
        
        // Alert: Trips without vehicles
        $departures->filter(fn($d) => $d['needs_vehicle'] && $d['mins_to_departure'] < 60)->each(function($d) use (&$alerts) {
            $alerts->push([
                'id' => 'vehicle-' . $d['id'],
                'type' => 'no_vehicle',
                'severity' => 'critical',
                'icon' => 'bus',
                'title' => 'Véhicule non assigné',
                'message' => "Départ {$d['origin']} → {$d['destination']} à {$d['departure_time']}",
                'trip_id' => $d['id'],
                'time' => "Dans {$d['mins_to_departure']} min",
            ]);
        });
        
        // Alert: Low occupancy trips departing soon
        $departures->filter(fn($d) => $d['alert_level'] === 'critical')->each(function($d) use (&$alerts) {
            $alerts->push([
                'id' => 'occupancy-' . $d['id'],
                'type' => 'low_occupancy',
                'severity' => 'warning',
                'icon' => 'seat',
                'title' => 'Faible remplissage',
                'message' => "{$d['occupancy_percent']}% - {$d['origin']} → {$d['destination']}",
                'trip_id' => $d['id'],
                'time' => "Départ dans {$d['mins_to_departure']} min",
            ]);
        });

        // 5. Mock Validations (Ticket cancellation requests, etc.)
        $validations = []; // Will be replaced with real data later

        // 6. Active Sellers Cash View
        $sellers = User::where('role', 'seller')
            ->whereHas('assignedStations', function($q) use ($stationIds) {
                $q->whereIn('stations.id', $stationIds);
            })
            ->with(['assignedStations'])
            ->take(10)
            ->get()
            ->map(function($seller) use ($todayStart, $todayEnd) {
                // Calculate actual today's revenue for this seller
                $todayRevenue = Ticket::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->sum('price');
                $ticketCount = Ticket::where('seller_id', $seller->id)
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->count();
                    
                return [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'station' => $seller->assignedStations->first()->name ?? '?',
                    'cash_balance' => $todayRevenue,
                    'tickets_sold' => $ticketCount,
                    'status' => 'online', // Could be based on last_activity_at
                ];
            })
            ->sortByDesc('cash_balance')
            ->values();

        return Inertia::render('Dashboards/Supervisor', [
            'departures' => $departures,
            'validations' => $validations,
            'alerts' => $alerts->values(),
            'sellers' => $sellers,
            'todayStats' => $todayStats,
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
