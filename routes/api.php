<?php

use App\Http\Controllers\Api\CrewAuthController;
use App\Http\Controllers\Api\CrewMessageController;
use App\Http\Controllers\Api\CrewStatusController;
use App\Http\Controllers\Api\CrewTicketController;
use App\Http\Controllers\Api\CrewTripController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OkohiDeleteController;
use App\Http\Controllers\Api\OkohiVerificationController;
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
Route::get('/okohi/verify', OkohiVerificationController::class)->name('okohi.verify');
Route::delete('/okohi/delete', OkohiDeleteController::class)->name('okohi.delete');

Route::middleware('throttle:public-catalog')->group(function () {
    Route::prefix('routes')->group(function () {
        Route::get('/', [RouteController::class, 'index'])->name('api.catalog.routes.index');
        Route::get('/{id}', [RouteController::class, 'show'])->name('api.catalog.routes.show');
    });

    Route::get('/trips/{route_id}/{date}', [TripController::class, 'byRouteAndDate'])
        ->whereUuid('route_id')
        ->where('date', '\\d{4}-\\d{2}-\\d{2}')
        ->name('api.catalog.trips.by-date');
});

// Routes protégées (nécessitent authentification)
Route::middleware(['auth:sanctum', 'non_crew', 'throttle:operational-api'])->group(function () {
    Route::prefix('vehicles')->middleware('role:admin,fleet_manager')->group(function () {
        Route::get('/', [VehicleController::class, 'index']);
        Route::get('/{id}/plan', [VehicleController::class, 'plan']);
    });
    Route::get('/vehicle-types', [VehicleController::class, 'types'])->middleware('role:admin,fleet_manager');

    Route::middleware('role:admin,supervisor,seller')->group(function () {
        Route::get('/trips/{id}', [TripController::class, 'show']);
        Route::post('/trips/{tripId}/suggest-seats', [OptimisationController::class, 'suggestSeats']);
        Route::get('/trips/{tripId}/occupancy', [OptimisationController::class, 'occupancy']);
    });

    Route::prefix('tickets')->name('api.tickets.')->group(function () {
        Route::post('/', [TicketController::class, 'store'])->middleware('role:admin,supervisor,seller')->name('store');
        Route::get('/export', [TicketController::class, 'export'])->middleware('role:admin,supervisor,seller,accountant')->name('export');
        Route::get('/{ticket}', [TicketController::class, 'show'])->middleware('role:admin,supervisor,seller')->name('show');
        Route::patch('/{ticket}/cancel', [TicketController::class, 'cancel'])->middleware('role:admin,supervisor,seller')->name('cancel');
    });

    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('role:admin,supervisor,accountant,executive');
});

Route::prefix('crew')->group(function () {
    Route::post('/login', [CrewAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'crew'])->group(function () {
        Route::post('/logout', [CrewAuthController::class, 'logout']);
        Route::get('/me', [CrewAuthController::class, 'me']);
        Route::get('/sessions', [CrewAuthController::class, 'sessions']);
        Route::delete('/sessions/{tokenId}', [CrewAuthController::class, 'revokeSession']);
        Route::post('/sessions/logout-others', [CrewAuthController::class, 'logoutOtherSessions']);

        Route::get('/trips', [CrewTripController::class, 'index']);
        Route::get('/trips/{trip}', [CrewTripController::class, 'show']);
        Route::patch('/trips/{trip}/status', [CrewTripController::class, 'updateStatus']);

        Route::get('/trips/{trip}/status-reports', [CrewStatusController::class, 'index']);
        Route::post('/trips/{trip}/status-reports', [CrewStatusController::class, 'store']);
        Route::get('/trips/{trip}/latest-position', [CrewStatusController::class, 'latestPosition']);

        Route::get('/trips/{trip}/messages', [CrewMessageController::class, 'index']);
        Route::post('/trips/{trip}/messages', [CrewMessageController::class, 'store']);
        Route::get('/trips/{trip}/messages/{message}/audio', [CrewMessageController::class, 'audio']);
        Route::delete('/trips/{trip}/messages/{message}', [CrewMessageController::class, 'destroy']);

        Route::post('/tickets/scan', [CrewTicketController::class, 'scan']);
        Route::get('/trips/{trip}/tickets', [CrewTicketController::class, 'tickets']);
        Route::patch('/trips/{trip}/tickets/{ticket}/board', [CrewTicketController::class, 'board']);
        Route::post('/trips/{trip}/tickets/sell', [CrewTicketController::class, 'sell']);
        Route::post('/trips/{trip}/tickets/sync', [CrewTicketController::class, 'sync']);
    });
});
