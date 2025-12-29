<?php

namespace App\Http\Controllers\Executive;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\User;
use App\Models\Station;
use App\Models\Route;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Display the executive analytics dashboard (read-only).
     */
    public function index(Request $request)
    {
        $period = $request->get('period', 'month'); // day, week, month, quarter, year
        
        // Determine date range based on period
        $now = Carbon::now();
        switch ($period) {
            case 'day':
                $startDate = $now->copy()->startOfDay();
                $compareStart = $now->copy()->subDay()->startOfDay();
                $compareEnd = $now->copy()->subDay()->endOfDay();
                break;
            case 'week':
                $startDate = $now->copy()->startOfWeek();
                $compareStart = $now->copy()->subWeek()->startOfWeek();
                $compareEnd = $now->copy()->subWeek()->endOfWeek();
                break;
            case 'quarter':
                $startDate = $now->copy()->startOfQuarter();
                $compareStart = $now->copy()->subQuarter()->startOfQuarter();
                $compareEnd = $now->copy()->subQuarter()->endOfQuarter();
                break;
            case 'year':
                $startDate = $now->copy()->startOfYear();
                $compareStart = $now->copy()->subYear()->startOfYear();
                $compareEnd = $now->copy()->subYear()->endOfYear();
                break;
            default: // month
                $startDate = $now->copy()->startOfMonth();
                $compareStart = $now->copy()->subMonth()->startOfMonth();
                $compareEnd = $now->copy()->subMonth()->endOfMonth();
        }
        $endDate = $now->copy()->endOfDay();

        // Current period statistics
        $currentRevenue = Ticket::whereBetween('created_at', [$startDate, $endDate])->sum('price');
        $currentTickets = Ticket::whereBetween('created_at', [$startDate, $endDate])->count();
        $currentTrips = Trip::whereBetween('departure_at', [$startDate, $endDate])->count();

        // Previous period for comparison
        $previousRevenue = Ticket::whereBetween('created_at', [$compareStart, $compareEnd])->sum('price');
        $previousTickets = Ticket::whereBetween('created_at', [$compareStart, $compareEnd])->count();

        // Calculate growth percentages
        $revenueGrowth = $previousRevenue > 0 
            ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1) 
            : 0;
        $ticketGrowth = $previousTickets > 0 
            ? round((($currentTickets - $previousTickets) / $previousTickets) * 100, 1) 
            : 0;

        // KPIs
        $kpis = [
            'revenue' => [
                'current' => $currentRevenue,
                'previous' => $previousRevenue,
                'growth' => $revenueGrowth,
            ],
            'tickets' => [
                'current' => $currentTickets,
                'previous' => $previousTickets,
                'growth' => $ticketGrowth,
            ],
            'trips' => [
                'current' => $currentTrips,
            ],
            'avg_ticket_price' => Ticket::whereBetween('created_at', [$startDate, $endDate])->avg('price') ?? 0,
            'avg_occupancy' => $this->calculateAverageOccupancy($startDate, $endDate),
        ];

        // Revenue trend (daily for month, weekly for quarter/year)
        $revenueTrend = $this->getRevenueTrend($startDate, $endDate, $period);

        // Top performing routes
        $topRoutes = Ticket::query()
            ->join('trips', 'tickets.trip_id', '=', 'trips.id')
            ->join('routes', 'trips.route_id', '=', 'routes.id')
            ->selectRaw('routes.id, routes.name, SUM(tickets.price) as revenue, COUNT(*) as ticket_count')
            ->whereBetween('tickets.created_at', [$startDate, $endDate])
            ->groupBy('routes.id', 'routes.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        // Revenue by station
        $revenueByStation = Ticket::query()
            ->join('stops', 'tickets.from_stop_id', '=', 'stops.id')
            ->join('stations', 'stops.station_id', '=', 'stations.id')
            ->selectRaw('stations.id, stations.name, SUM(tickets.price) as revenue, COUNT(*) as ticket_count')
            ->whereBetween('tickets.created_at', [$startDate, $endDate])
            ->groupBy('stations.id', 'stations.name')
            ->orderByDesc('revenue')
            ->get();

        // Fleet utilization
        $totalVehicles = Vehicle::count();
        $usedVehicles = Trip::whereBetween('departure_at', [$startDate, $endDate])
            ->distinct('vehicle_id')
            ->count('vehicle_id');
        $fleetUtilization = $totalVehicles > 0 ? round(($usedVehicles / $totalVehicles) * 100, 1) : 0;

        // Monthly comparison (for year view)
        $monthlyRevenue = [];
        if ($period === 'year') {
            for ($i = 0; $i < 12; $i++) {
                $monthStart = $now->copy()->startOfYear()->addMonths($i)->startOfMonth();
                $monthEnd = $monthStart->copy()->endOfMonth();
                $monthlyRevenue[] = [
                    'month' => $monthStart->format('M'),
                    'revenue' => Ticket::whereBetween('created_at', [$monthStart, $monthEnd])->sum('price'),
                ];
            }
        }

        return Inertia::render('Executive/Analytics', [
            'kpis' => $kpis,
            'revenueTrend' => $revenueTrend,
            'topRoutes' => $topRoutes,
            'revenueByStation' => $revenueByStation,
            'fleetUtilization' => $fleetUtilization,
            'monthlyRevenue' => $monthlyRevenue,
            'period' => $period,
            'dateRange' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
        ]);
    }

    /**
     * Calculate average occupancy rate for trips.
     */
    private function calculateAverageOccupancy($startDate, $endDate)
    {
        $trips = Trip::with(['vehicle', 'tripSeatOccupancies'])
            ->whereBetween('departure_at', [$startDate, $endDate])
            ->get();

        if ($trips->isEmpty()) {
            return 0;
        }

        $totalOccupancy = 0;
        $tripCount = 0;

        foreach ($trips as $trip) {
            $seatCount = $trip->vehicle?->seat_count ?? 0;
            if ($seatCount > 0) {
                $occupied = $trip->tripSeatOccupancies->count();
                $totalOccupancy += ($occupied / $seatCount) * 100;
                $tripCount++;
            }
        }

        return $tripCount > 0 ? round($totalOccupancy / $tripCount, 1) : 0;
    }

    /**
     * Get revenue trend data for charts.
     */
    private function getRevenueTrend($startDate, $endDate, $period)
    {
        $groupBy = $period === 'year' ? 'WEEK' : 'DATE';
        
        return Ticket::query()
            ->selectRaw("{$groupBy}(created_at) as period_key, DATE(MIN(created_at)) as date, SUM(price) as revenue, COUNT(*) as tickets")
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('d M'),
                    'revenue' => $item->revenue,
                    'tickets' => $item->tickets,
                ];
            });
    }
}
