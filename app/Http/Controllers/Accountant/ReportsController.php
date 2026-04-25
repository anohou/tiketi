<?php

namespace App\Http\Controllers\Accountant;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportsController extends Controller
{
    /**
     * Display the accountant reports dashboard.
     */
    public function index(Request $request)
    {
        // Default date range: current month
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfDay()->toDateString());
        $stationId = $request->get('station_id');
        $sellerId = $request->get('seller_id');

        // Build tickets query with filters
        $ticketsQuery = Ticket::query()
            ->with(['trip.route', 'trip.vehicle', 'seller', 'fromStation', 'toStation'])
            ->whereBetween('created_at', [$startDate, Carbon::parse($endDate)->endOfDay()]);

        if ($stationId) {
            $ticketsQuery->where('from_station_id', $stationId);
        }

        if ($sellerId) {
            $ticketsQuery->where('seller_id', $sellerId);
        }

        $tickets = $ticketsQuery->orderBy('created_at', 'desc')->paginate(50);

        // Calculate statistics
        $stats = [
            'total_tickets' => $ticketsQuery->count(),
            'total_revenue' => $ticketsQuery->sum('price'),
            'avg_ticket_price' => $ticketsQuery->avg('price') ?? 0,
        ];

        // Revenue by seller
        $revenueBySeller = Ticket::query()
            ->selectRaw('seller_id, SUM(price) as total, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, Carbon::parse($endDate)->endOfDay()])
            ->groupBy('seller_id')
            ->with('seller:id,name')
            ->get();

        // Revenue by station
        $revenueByStation = Ticket::query()
            ->selectRaw('from_station_id as station_id, SUM(price) as total, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, Carbon::parse($endDate)->endOfDay()])
            ->groupBy('from_station_id')
            ->get()
            ->map(function ($item) {
                $station = Station::find($item->station_id);

                return [
                    'station_id' => $item->station_id,
                    'station_name' => $station?->name ?? 'Unknown',
                    'total' => $item->total,
                    'count' => $item->count,
                ];
            });

        // Daily revenue trend
        $dailyRevenue = Ticket::query()
            ->selectRaw('DATE(created_at) as date, SUM(price) as total, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate, Carbon::parse($endDate)->endOfDay()])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Get filter options
        $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name']);
        $sellers = User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Accountant/Reports', [
            'tickets' => $tickets,
            'stats' => $stats,
            'revenueBySeller' => $revenueBySeller,
            'revenueByStation' => $revenueByStation,
            'dailyRevenue' => $dailyRevenue,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'station_id' => $stationId,
                'seller_id' => $sellerId,
            ],
            'stations' => $stations,
            'sellers' => $sellers,
        ]);
    }

    /**
     * Export reports as CSV.
     */
    public function export(Request $request)
    {
        $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', Carbon::now()->endOfDay()->toDateString());

        $tickets = Ticket::query()
            ->with(['trip.route', 'seller', 'fromStation', 'toStation'])
            ->whereBetween('created_at', [$startDate, Carbon::parse($endDate)->endOfDay()])
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'rapport_ventes_'.$startDate.'_'.$endDate.'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($tickets) {
            $file = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Header row
            fputcsv($file, [
                'N° Ticket',
                'Date',
                'Heure',
                'Route',
                'Départ',
                'Arrivée',
                'Place',
                'Prix (FCFA)',
                'Vendeur',
                'Passager',
            ], ';');

            foreach ($tickets as $ticket) {
                fputcsv($file, [
                    $ticket->ticket_number,
                    Carbon::parse($ticket->created_at)->format('d/m/Y'),
                    Carbon::parse($ticket->created_at)->format('H:i'),
                    $ticket->trip?->route?->name ?? '',
                    $ticket->fromStation?->name ?? '',
                    $ticket->toStation?->name ?? '',
                    $ticket->seat_number ?? '',
                    $ticket->price,
                    $ticket->seller?->name ?? '',
                    $ticket->passenger_name ?? 'Anonyme',
                ], ';');
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
