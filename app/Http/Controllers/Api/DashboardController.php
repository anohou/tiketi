<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Trip;

class DashboardController extends Controller
{
    public function stats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_trips_today' => Trip::whereDate('departure_at', today())->count(),
                'total_tickets_today' => Ticket::whereDate('created_at', today())->where('status', '!=', 'cancelled')->count(),
                'total_revenue_today' => Ticket::whereDate('created_at', today())->where('status', '!=', 'cancelled')->sum('price'),
                'occupancy_rate' => 0, // À implémenter
            ],
            'message' => 'Statistiques récupérées avec succès',
        ]);
    }
}
