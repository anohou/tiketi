<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\SupervisorDashboardController;
use App\Http\Controllers\SellerDashboardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/home', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // =========================================
    // ADMIN ROUTES - Configuration & Statistics
    // =========================================
    Route::prefix('admin')->middleware('role:admin')->name('admin.')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        
        // Settings Landing Page
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index');
        
        // CRUDs
        Route::resource('destinations', \App\Http\Controllers\Admin\DestinationController::class);
        Route::resource('stations', \App\Http\Controllers\Admin\StationController::class);

        Route::resource('vehicle-types', \App\Http\Controllers\Admin\VehicleTypeController::class);
        Route::resource('vehicles', \App\Http\Controllers\Admin\VehicleController::class);
        Route::resource('routes', \App\Http\Controllers\Admin\RouteController::class);
        
        // Route Stops Management
        Route::get('routes/{route}/stops', [\App\Http\Controllers\Admin\RouteStopOrderController::class, 'index'])->name('routes.stops.index');
        Route::post('routes/{route}/stops', [\App\Http\Controllers\Admin\RouteStopOrderController::class, 'store'])->name('routes.stops.store');
        Route::delete('routes/{route}/stops/{routeStopOrder}', [\App\Http\Controllers\Admin\RouteStopOrderController::class, 'destroy'])->name('routes.stops.destroy');
        Route::put('routes/{route}/stops/reorder', [\App\Http\Controllers\Admin\RouteStopOrderController::class, 'reorder'])->name('routes.stops.reorder');

        Route::resource('trips', \App\Http\Controllers\Admin\TripController::class);
        Route::resource('route-fares', \App\Http\Controllers\Admin\RouteFareController::class);
        Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
        Route::put('users/{user}/toggle-active', [\App\Http\Controllers\Admin\UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::resource('assignments', \App\Http\Controllers\Admin\UserAssignmentController::class)->only(['index','store','update','destroy']);
        
        // Ticket Settings
        Route::get('ticket-settings', [\App\Http\Controllers\Admin\TicketSettingController::class, 'index'])->name('ticket-settings.index');
        Route::put('ticket-settings', [\App\Http\Controllers\Admin\TicketSettingController::class, 'update'])->name('ticket-settings.update');
    });

    // =========================================
    // SUPERVISOR ROUTES - Control Tower & Multi-Station Ticketing
    // =========================================
    Route::prefix('supervisor')->middleware('role:admin,supervisor')->name('supervisor.')->group(function () {
        Route::get('/control-tower', [SupervisorDashboardController::class, 'index'])->name('control-tower');
        // Alias for backward compatibility
        Route::get('/', [SupervisorDashboardController::class, 'index'])->name('dashboard');
        // Supervisor ticketing - can see trips from all stations
        Route::get('/ticketing', [\App\Http\Controllers\Seller\TicketingController::class, 'index'])->name('ticketing');
    });

    // =========================================
    // SELLER ROUTES - Ticketing & Sales
    // =========================================
    Route::prefix('seller')->middleware('role:admin,supervisor,seller')->name('seller.')->group(function () {
        // Dashboard
        Route::get('/dashboard', [SellerDashboardController::class, 'index'])->name('dashboard');
        
        // Ticketing (POS Interface)
        Route::get('/ticketing', [\App\Http\Controllers\Seller\TicketingController::class, 'index'])->name('ticketing');
        Route::get('/ticketing-horizontal', [\App\Http\Controllers\Seller\TicketingController::class, 'horizontal'])->name('ticketing.horizontal');
        Route::get('/tickets', [\App\Http\Controllers\Seller\TicketController::class, 'index'])->name('tickets.index');
        
        // API-like endpoints for ticketing
        Route::post('/tickets', [\App\Http\Controllers\Api\TicketController::class, 'store'])->name('tickets.store');
        Route::delete('/tickets/{ticket}', [\App\Http\Controllers\Api\TicketController::class, 'destroy'])->name('tickets.destroy');
        Route::get('/trips/{trip}/seat-map', [\App\Http\Controllers\Api\TripController::class, 'seatMap'])->name('trips.seatmap');
        Route::get('/trips/{trip}/suggest-seats', [\App\Http\Controllers\Api\TripController::class, 'suggestSeats'])->name('trips.suggest-seats');
        
        // Trip creation (for sellers)
        Route::post('/trips', [\App\Http\Controllers\Admin\TripController::class, 'store'])->name('trips.store');
    });

    // =========================================
    // ACCOUNTANT ROUTES - Financial Reports
    // =========================================
    Route::prefix('accountant')->middleware('role:admin,accountant')->name('accountant.')->group(function () {
        Route::get('/reports', [\App\Http\Controllers\Accountant\ReportsController::class, 'index'])->name('reports');
        Route::get('/export', [\App\Http\Controllers\Accountant\ReportsController::class, 'export'])->name('export');
    });

    // =========================================
    // EXECUTIVE ROUTES - Analytics Dashboard (Read-Only)
    // =========================================
    Route::prefix('executive')->middleware('role:admin,executive')->name('executive.')->group(function () {
        Route::get('/analytics', [\App\Http\Controllers\Executive\AnalyticsController::class, 'index'])->name('analytics');
    });

    // =========================================
    // SHARED ROUTES - Available to all authenticated users
    // =========================================
    Route::middleware('role:admin,supervisor,seller,accountant,executive')->group(function () {
        Route::get('/trips', [\App\Http\Controllers\Api\TripController::class, 'index'])->name('trips.index');
        Route::get('/tickets', [\App\Http\Controllers\Api\TicketController::class, 'index'])->name('tickets.index');
    });
    
    // Printing routes
    Route::get('/tickets/{ticket}/print', [\App\Http\Controllers\TicketPrintController::class, 'print'])->name('tickets.print');
    Route::post('/tickets/print-multiple', [\App\Http\Controllers\TicketPrintController::class, 'printMultiple'])->name('tickets.print-multiple');
});

require __DIR__.'/auth.php';
