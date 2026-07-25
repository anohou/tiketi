<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $tripsToday = Trip::with(['vehicle.vehicleType', 'tripSeatOccupancies.ticket'])
            ->whereDate('departure_at', today())
            ->get();

        $totalCapacity = 0;
        $totalOccupied = 0;

        foreach ($tripsToday as $trip) {
            $totalCapacity += $trip->total_seats;
            $totalOccupied += $trip->occupied_seats_count;
        }

        $occupancyRate = $totalCapacity > 0 ? round(($totalOccupied / $totalCapacity) * 100, 2) : 0.0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_trips_today' => $tripsToday->count(),
                'total_tickets_today' => Ticket::whereDate('created_at', today())->where('status', 'issued')->count(),
                'total_revenue_today' => Ticket::whereDate('created_at', today())->where('status', 'issued')->sum(DB::raw('COALESCE(amount_collected, price)')),
                'occupancy_rate' => $occupancyRate,
            ],
            'message' => 'Statistiques récupérées avec succès',
        ]);
    }
}
