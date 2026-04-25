<?php

use App\Http\Controllers\Api\OptimisationController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Routes publiques (trajets et véhicules)
Route::prefix('routes')->group(function () {
    Route::get('/', [RouteController::class, 'index']); // GET /api/routes
    Route::get('/{id}', [RouteController::class, 'show']); // GET /api/routes/{id}
});

Route::prefix('vehicles')->group(function () {
    Route::get('/', [VehicleController::class, 'index']); // GET /api/vehicles
    Route::get('/{id}/plan', [VehicleController::class, 'plan']); // GET /api/vehicles/{id}/plan
});

Route::get('/vehicle-types', [VehicleController::class, 'types']); // GET /api/vehicle-types

// Routes pour les voyages
Route::prefix('trips')->group(function () {
    Route::get('/{route_id}/{date}', [TripController::class, 'byRouteAndDate']); // GET /api/trips/{route_id}/{date}
    Route::get('/{id}', [TripController::class, 'show']); // GET /api/trips/{id}
    Route::post('/{tripId}/suggest-seats', [OptimisationController::class, 'suggestSeats']); // POST /api/trips/{id}/suggest-seats
    Route::get('/{tripId}/occupancy', [OptimisationController::class, 'occupancy']); // GET /api/trips/{id}/occupancy
});

// Routes pour les billets/réservations
Route::prefix('tickets')->name('api.tickets.')->group(function () {
    Route::post('/', [TicketController::class, 'store'])->name('store'); // POST /api/tickets
    Route::get('/{ticket}', [TicketController::class, 'show'])->name('show'); // GET /api/tickets/{ticket}
    Route::patch('/{ticket}/cancel', [TicketController::class, 'cancel'])->name('cancel'); // PATCH /api/tickets/{ticket}/cancel
});

// Routes protégées (nécessitent authentification)
Route::middleware('auth:sanctum')->group(function () {
    // Dashboard stats
    Route::get('/dashboard/stats', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'total_trips_today' => \App\Models\Trip::whereDate('departure_at', today())->count(),
                'total_tickets_today' => \App\Models\Ticket::whereDate('created_at', today())->count(),
                'total_revenue_today' => \App\Models\Ticket::whereDate('created_at', today())->sum('price'),
                'occupancy_rate' => 0, // À implémenter
            ],
            'message' => 'Statistiques récupérées avec succès',
        ]);
    });
});
