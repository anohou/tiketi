<?php

namespace App\Http\Controllers;

use App\Models\Route;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminDashboardController extends Controller
{
    public function index()
    {
        // Get today's date
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();
        $yesterday = Carbon::yesterday();

        // Calculate statistics
        $totalSales = Ticket::count();
        $todaySales = Ticket::whereDate('created_at', $today)->count();
        $yesterdaySales = Ticket::whereDate('created_at', $yesterday)->count();
        $monthlySales = Ticket::where('created_at', '>=', $thisMonth)->count();

        // Revenue calculations (assuming each ticket has a price)
        $totalRevenue = Ticket::sum('price') ?? 0;
        $todayRevenue = Ticket::whereDate('created_at', $today)->sum('price') ?? 0;
        $yesterdayRevenue = Ticket::whereDate('created_at', $yesterday)->sum('price') ?? 0;
        $monthlyRevenue = Ticket::where('created_at', '>=', $thisMonth)->sum('price') ?? 0;

        // Growth calculations
        $salesGrowth = $yesterdaySales > 0 ? round((($todaySales - $yesterdaySales) / $yesterdaySales) * 100, 1) : 0;
        $revenueGrowth = $yesterdayRevenue > 0 ? round((($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100, 1) : 0;

        // Vehicle and trip statistics
        $totalVehicles = Vehicle::count();
        $activeVehicles = Vehicle::whereHas('trips', function ($q) {
            $q->where('departure_at', '>=', now())->where('departure_at', '<=', now()->addHours(24));
        })->count();
        $activeTrips = Trip::where('departure_at', '>=', now())->count();
        $totalStations = Station::count();
        $activeStations = Station::where('active', true)->count();
        $totalRoutes = Route::count();

        // User statistics by role
        $usersByRole = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();

        $totalUsers = User::count();
        $activeUsers = User::where('active', true)->count();

        // System Health Metrics
        $systemHealth = [
            'database' => $this->checkDatabaseHealth(),
            'trips_today' => Trip::whereBetween('departure_at', [$today, $today->copy()->endOfDay()])->count(),
            'pending_departures' => Trip::where('departure_at', '>=', now())
                ->where('departure_at', '<=', now()->addHours(2))
                ->where('status', '!=', 'departed')
                ->count(),
            'vehicles_without_trips' => Vehicle::whereDoesntHave('trips', function ($q) {
                $q->where('departure_at', '>=', now())->where('departure_at', '<=', now()->addHours(24));
            })->count(),
            'stations_active' => $activeStations,
            'stations_inactive' => $totalStations - $activeStations,
        ];

        // Sales trend for the last 7 days
        $salesTrend = Ticket::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(price) as revenue')
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('d M'),
                    'count' => $item->count,
                    'revenue' => $item->revenue,
                ];
            });

        // Top selling routes
        $topRoutes = Route::withCount('trips')
            ->orderBy('trips_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($route) {
                return [
                    'name' => $route->name,
                    'trips' => $route->trips_count,
                ];
            });

        // Vehicle occupancy rate (simplified)
        $vehicleOccupancy = Vehicle::with(['vehicleType'])->limit(10)->get()->map(function ($vehicle) {
            $recentTrips = $vehicle->trips()
                ->where('departure_at', '>=', now()->subDays(7))
                ->count();

            return [
                'name' => $vehicle->identifier,
                'type' => $vehicle->vehicleType?->name ?? 'Standard',
                'occupancy' => min(100, $recentTrips * 15), // Simplified calculation
            ];
        });

        return Inertia::render('Dashboards/Admin', [
            'stats' => [
                'totalSales' => $totalSales,
                'todaySales' => $todaySales,
                'monthlySales' => $monthlySales,
                'salesGrowth' => $salesGrowth,
                'totalRevenue' => $totalRevenue,
                'todayRevenue' => $todayRevenue,
                'monthlyRevenue' => $monthlyRevenue,
                'revenueGrowth' => $revenueGrowth,
                'totalVehicles' => $totalVehicles,
                'activeVehicles' => $activeVehicles,
                'activeTrips' => $activeTrips,
                'totalStations' => $totalStations,
                'totalRoutes' => $totalRoutes,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'sellers' => $usersByRole['seller'] ?? 0,
                'supervisors' => $usersByRole['supervisor'] ?? 0,
                'accountants' => $usersByRole['accountant'] ?? 0,
                'executives' => $usersByRole['executive'] ?? 0,
                'admins' => $usersByRole['admin'] ?? 0,
            ],
            'systemHealth' => $systemHealth,
            'charts' => [
                'salesTrend' => $salesTrend,
                'topRoutes' => $topRoutes,
                'vehicleOccupancy' => $vehicleOccupancy,
            ],
            'links' => [
                ['label' => 'Gares', 'href' => '/admin/stations', 'icon' => 'station', 'count' => $totalStations],

                ['label' => 'Routes', 'href' => '/admin/routes', 'icon' => 'route', 'count' => $totalRoutes],
                ['label' => 'Types de Véhicules', 'href' => '/admin/vehicle-types', 'icon' => 'vehicle-type'],
                ['label' => 'Véhicules', 'href' => '/admin/vehicles', 'icon' => 'vehicle', 'count' => $totalVehicles],
                ['label' => 'Voyages', 'href' => '/admin/trips', 'icon' => 'trip', 'count' => $activeTrips],
                ['label' => 'Tarifs', 'href' => '/admin/route-fares', 'icon' => 'fare'],
                ['label' => 'Utilisateurs', 'href' => '/admin/users', 'icon' => 'user', 'count' => $totalUsers],
                ['label' => 'Assignations', 'href' => '/admin/assignments', 'icon' => 'assignment'],
                ['label' => 'Config. Tickets', 'href' => '/admin/ticket-settings', 'icon' => 'settings'],
            ],
        ]);
    }

    private function checkDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency' => $latency,
                'message' => 'Connexion OK',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'latency' => null,
                'message' => 'Erreur de connexion',
            ];
        }
    }
}
