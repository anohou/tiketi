<?php

use App\Http\Controllers\Accountant\ReportsController;
use App\Http\Controllers\Admin\AuthorizedDeviceController;
use App\Http\Controllers\Admin\DestinationController;
use App\Http\Controllers\Admin\LoyaltySettingController;
use App\Http\Controllers\Admin\OkohiConnectController;
use App\Http\Controllers\Admin\OkohiRewardController;
use App\Http\Controllers\Admin\OkohiRewardsController;
use App\Http\Controllers\Admin\OkohiTransactionsController;
use App\Http\Controllers\Admin\RouteController;
use App\Http\Controllers\Admin\RouteFareController;
use App\Http\Controllers\Admin\RouteStopOrderController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StationController;
use App\Http\Controllers\Admin\TicketSettingController;
use App\Http\Controllers\Admin\TripController;
use App\Http\Controllers\Admin\UserAssignmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\Admin\VehicleTypeController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\Executive\AnalyticsController;
use App\Http\Controllers\Fleet\CrewMemberController;
use App\Http\Controllers\Fleet\FleetAssignmentController;
use App\Http\Controllers\Fleet\FleetDashboardController;
use App\Http\Controllers\Fleet\FleetVehicleController;
use App\Http\Controllers\Fleet\FleetVehicleTypeController;
use App\Http\Controllers\Fleet\StationVehicleAssignmentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Fleet\VehicleCrewAssignmentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Seller\OkohiRewardRequestController;
use App\Http\Controllers\Seller\SettingsController as SellerSettingsController;
use App\Http\Controllers\Seller\TicketController;
use App\Http\Controllers\Seller\TicketingController;
use App\Http\Controllers\Seller\TransferPoolController;
use App\Http\Controllers\SellerDashboardController;
use App\Http\Controllers\SupervisorDashboardController;
use App\Http\Controllers\TicketCompensationController;
use App\Http\Controllers\TicketPrintController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    $isTenant = function_exists('tenancy') && tenancy()->initialized;
    $data = [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ];

    if ($isTenant) {
        $data['canResetPassword'] = Route::has('password.request');
        $data['status'] = session('status');

        return Inertia::render('Welcome', $data);
    }

    // Page d'accueil du domaine central : présentation commerciale fusionnée
    return Inertia::render('Presentation');
});

Route::get('/dashboard', function () {
    $user = request()->user();

    if (! $user) {
        return redirect()->route('login');
    }

    return match ($user->role) {
        'superadmin', 'super_admin' => redirect()->route('landlord.tenants.index'),
        'admin' => redirect()->route('admin.dashboard'),
        'fleet_manager' => redirect()->route('fleet.dashboard'),
        'supervisor' => redirect()->route('supervisor.dashboard'),
        'seller' => redirect()->route('seller.dashboard'),
        'accountant' => redirect()->route('accountant.reports'),
        'executive' => redirect()->route('executive.analytics'),
        default => redirect()->route('login'),
    };
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/tids', [TicketingController::class, 'tids'])->name('tids');

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

// =========================================
// PUBLIC HELP - accessible avant connexion
// =========================================
// Documentation utilisateur publique (tenant et domaine central) : aucune
// authentification requise. Les visiteurs voient tous les guides avec un
// sélecteur de rôle ; les utilisateurs connectés retrouvent leur parcours
// personnalisé. Sur le domaine central, la page reste en mode public.
Route::get('/help', fn () => Inertia::render('Help/Index', [
    'public' => ! request()->user() || ! (function_exists('tenancy') && tenancy()->initialized),
]))->name('help.index');

// Page publique de présentation commerciale (transporteurs professionnels),
// accessible sur le tenant et le domaine central, sans authentification.
Route::get('/presentation', fn () => Inertia::render('Presentation'))
    ->name('presentation');

// Réception du formulaire de contact de la page de présentation (envoi SMTP)
Route::post('/contact', [ContactController::class, 'send'])
    ->name('contact.send');

Route::middleware(['auth', 'tenant.initialized', 'authorized.web.device'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // =========================================
    // ADMIN ROUTES - Configuration & Statistics
    // =========================================
    Route::prefix('admin')->middleware('role:admin')->name('admin.')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // Settings sub-pages (admin only). The landing page is the shared /settings route.
        Route::get('/settings/enterprise', [SettingsController::class, 'enterprise'])->name('settings.enterprise');
        Route::post('/settings/enterprise', [SettingsController::class, 'updateEnterprise'])->name('settings.enterprise.update');
        Route::get('/settings/devices', [AuthorizedDeviceController::class, 'index'])->name('settings.devices.index');
        Route::put('/settings/devices/restrictions', [AuthorizedDeviceController::class, 'updateRestrictions'])->name('settings.devices.restrictions');
        Route::patch('/settings/devices/{device}', [AuthorizedDeviceController::class, 'update'])->name('settings.devices.update');

        // CRUDs
        Route::resource('destinations', DestinationController::class);
        Route::resource('stations', StationController::class);

        Route::resource('vehicle-types', VehicleTypeController::class);
        Route::resource('vehicles', VehicleController::class);
        Route::resource('routes', RouteController::class);

        // Route Stops Management
        Route::get('routes/{route}/stops', [RouteStopOrderController::class, 'index'])->name('routes.stops.index');
        Route::post('routes/{route}/stops', [RouteStopOrderController::class, 'store'])->name('routes.stops.store');
        Route::delete('routes/{route}/stops/{routeStopOrder}', [RouteStopOrderController::class, 'destroy'])->name('routes.stops.destroy');
        Route::put('routes/{route}/stops/reorder', [RouteStopOrderController::class, 'reorder'])->name('routes.stops.reorder');

        Route::resource('trips', TripController::class);
        Route::resource('route-fares', RouteFareController::class);
        Route::resource('users', UserController::class);
        Route::put('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::resource('assignments', UserAssignmentController::class)->only(['index', 'store', 'update', 'destroy']);

        // Ticket Settings
        Route::get('ticket-settings', [TicketSettingController::class, 'index'])->name('ticket-settings.index');
        Route::put('ticket-settings', [TicketSettingController::class, 'update'])->name('ticket-settings.update');

        // Loyalty / Fidélisation (Okohi)
        Route::get('settings/loyalty', [LoyaltySettingController::class, 'index'])->name('settings.loyalty');
        Route::post('settings/loyalty/connect', [OkohiConnectController::class, 'connect'])->name('settings.loyalty.connect');
        Route::delete('settings/loyalty/disconnect', [OkohiConnectController::class, 'disconnect'])->name('settings.loyalty.disconnect');
        Route::get('settings/loyalty/transactions', [OkohiTransactionsController::class, 'index'])->name('settings.loyalty.transactions');
        Route::get('settings/loyalty/customers/{customerNumber}', [OkohiRewardController::class, 'customer'])->name('settings.loyalty.customer');
        Route::post('settings/loyalty/customers/{customerNumber}/grant-reward', [OkohiRewardController::class, 'grant'])->name('settings.loyalty.grant');
        // Rewards catalog management
        Route::get('settings/loyalty/rewards', [OkohiRewardsController::class, 'index'])->name('settings.loyalty.rewards.index');
        Route::get('settings/loyalty/parameters', [OkohiRewardsController::class, 'parameters'])->name('settings.loyalty.parameters');
        Route::put('settings/loyalty/parameters', [OkohiRewardsController::class, 'updateParameters'])->name('settings.loyalty.parameters.update');
        Route::post('settings/loyalty/rewards', [OkohiRewardsController::class, 'store'])->name('settings.loyalty.rewards.store');
        Route::put('settings/loyalty/rewards/{id}', [OkohiRewardsController::class, 'update'])->name('settings.loyalty.rewards.update');
        Route::delete('settings/loyalty/rewards/{id}', [OkohiRewardsController::class, 'destroy'])->name('settings.loyalty.rewards.destroy');
    });

    // =========================================
    // SUPERVISOR ROUTES - Control Tower & Multi-Station Ticketing
    // =========================================
    Route::prefix('supervisor')->middleware('role:admin,supervisor')->name('supervisor.')->group(function () {
        Route::get('/control-tower', [SupervisorDashboardController::class, 'index'])->name('control-tower');
        // Alias for backward compatibility
        Route::get('/', [SupervisorDashboardController::class, 'index'])->name('dashboard');
        // Supervisor ticketing - can see trips from all stations
        Route::get('/ticketing', [TicketingController::class, 'index'])->name('ticketing');
        Route::get('/compensations', [TicketCompensationController::class, 'index'])->name('compensations.index');

        // Settings / Paramétrage (landing page moved to shared /settings)
        Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
        Route::resource('users', UserController::class);
        Route::put('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::resource('assignments', UserAssignmentController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // =========================================
    // SELLER ROUTES - Ticketing & Sales
    // =========================================
    Route::prefix('seller')->middleware('role:admin,supervisor,seller')->name('seller.')->group(function () {
        // Dashboard
        Route::get('/dashboard', [SellerDashboardController::class, 'index'])->name('dashboard');

        // Ticketing (POS Interface)
        Route::get('/ticketing', [TicketingController::class, 'index'])->name('ticketing');
        Route::get('/ticketing-focus', [TicketingController::class, 'focus'])->name('ticketing.focus');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');

        // API-like endpoints for ticketing
        Route::post('/tickets', [App\Http\Controllers\Api\TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets/export', [App\Http\Controllers\Api\TicketController::class, 'export'])->name('tickets.export');
        Route::get('/tickets/{ticket}/data', [App\Http\Controllers\Api\TicketController::class, 'show'])->name('tickets.show-data');
        Route::delete('/tickets/{ticket}', [App\Http\Controllers\Api\TicketController::class, 'destroy'])->name('tickets.destroy');
        Route::post('/tickets/{ticket}/compensations', [TicketCompensationController::class, 'store'])->name('tickets.compensations.store');
        Route::patch('/compensations/{compensation}/approve', [TicketCompensationController::class, 'approve'])->name('compensations.approve');
        Route::get('/trips/{trip}/seat-map', [App\Http\Controllers\Api\TripController::class, 'seatMap'])->name('trips.seatmap');
        Route::get('/trips/{trip}/available-vehicles', [TicketingController::class, 'availableVehicles'])->name('trips.available-vehicles');
        Route::get('/trips/{trip}/suggest-seats', [App\Http\Controllers\Api\TripController::class, 'suggestSeats'])->name('trips.suggest-seats');
        Route::get('/trips/{trip}/details', [App\Http\Controllers\Api\TripController::class, 'details'])->name('trips.details');
        Route::get('/trips/{trip}/latest-position', [App\Http\Controllers\Api\TripController::class, 'latestPosition'])->name('trips.latest-position');
        Route::get('/trips/{trip}/status-reports', [App\Http\Controllers\Api\TripController::class, 'statusReports'])->name('trips.status-reports');

        // Trip creation (for sellers)
        Route::post('/trips', [TripController::class, 'store'])->name('trips.store');
        Route::put('/trips/{trip}', [TicketingController::class, 'updateTrip'])->name('trips.update');
        Route::patch('/trips/{trip}/vehicle', [TicketingController::class, 'assignVehicle'])->name('trips.assign-vehicle');
        Route::get('/transfer-pool', [TransferPoolController::class, 'index'])->name('transfer-pool.index');
        Route::patch('/transfer-pool/{connection}/ready', [TransferPoolController::class, 'markReady'])->name('transfer-pool.ready');
        Route::post('/trips/{trip}/assign-connection', [TransferPoolController::class, 'assign'])->name('transfer-pool.assign');
        Route::post('/trips/{trip}/allocate-connections', [TransferPoolController::class, 'autoAllocate'])->name('transfer-pool.allocate');
        Route::patch('/trips/{trip}/depart', [TransferPoolController::class, 'depart'])->name('trips.depart');
        Route::patch('/trips/{trip}/status', [TicketingController::class, 'updateStatus'])->name('trips.status');

        // Okohi Loyalty Integration for seller
        Route::get('/okohi/customers/{customerNumber}', [OkohiRewardController::class, 'customer'])->name('okohi.customer');
        Route::get('/okohi/reward-requests-pending', [OkohiRewardRequestController::class, 'pendingForSeat'])->name('okohi.requests.pending-seat');
        Route::get('/okohi/reward-requests-pending-trip', [OkohiRewardRequestController::class, 'pendingForTrip'])->name('okohi.requests.pending-trip');
        Route::post('/okohi/reward-requests', [OkohiRewardRequestController::class, 'store'])->name('okohi.requests.store');
        Route::get('/okohi/reward-requests/{request}', [OkohiRewardRequestController::class, 'show'])->name('okohi.requests.show');
        Route::delete('/okohi/reward-requests/{request}', [OkohiRewardRequestController::class, 'destroy'])->name('okohi.requests.destroy');
        Route::post('/okohi/reward-requests/{request}/confirm-cash', [OkohiRewardRequestController::class, 'confirmCash'])->name('okohi.requests.confirm-cash');

        // Seller settings - read-only consultation of the seller's scope
        Route::prefix('settings')->middleware('role:seller')->name('settings.')->group(function () {
            Route::get('/', [SellerSettingsController::class, 'index'])->name('index');
            Route::get('/entreprise', [SellerSettingsController::class, 'company'])->name('company');
            Route::get('/fidelisation', [SellerSettingsController::class, 'loyalty'])->name('loyalty');
            // Read-only Okohi data endpoints for the seller (consultation only).
            // The company-wide transaction history is intentionally NOT exposed to sellers.
            Route::get('/fidelisation/parameters', [OkohiRewardsController::class, 'parameters'])->name('loyalty.parameters');
            Route::get('/fidelisation/rewards', [OkohiRewardsController::class, 'index'])->name('loyalty.rewards.index');
            Route::get('/gares-destinations', [SellerSettingsController::class, 'stations'])->name('stations');
            Route::get('/trajets', [SellerSettingsController::class, 'routes'])->name('routes');
            Route::get('/vehicules', [SellerSettingsController::class, 'vehicles'])->name('vehicles');
            Route::get('/equipe', [SellerSettingsController::class, 'team'])->name('team');
            Route::get('/affectations', [SellerSettingsController::class, 'assignments'])->name('assignments');
            Route::get('/voyages', [SellerSettingsController::class, 'trips'])->name('trips');
            Route::get('/profil', [SellerSettingsController::class, 'profile'])->name('profile');
        });
    });

    // =========================================
    // ACCOUNTANT ROUTES - Financial Reports
    // =========================================
    Route::prefix('accountant')->middleware('role:admin,accountant')->name('accountant.')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
        Route::get('/export', [ReportsController::class, 'export'])->name('export');
    });

    // =========================================
    // EXECUTIVE ROUTES - Analytics Dashboard (Read-Only)
    // =========================================
    Route::prefix('executive')->middleware('role:admin,executive')->name('executive.')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');
    });

    // =========================================
    // FLEET MANAGER ROUTES - Vehicle Registry & Seat Maps
    // =========================================
    Route::prefix('fleet')->middleware('role:admin,fleet_manager')->name('fleet.')->group(function () {
        Route::get('/', [FleetDashboardController::class, 'index'])->name('dashboard');

        Route::resource('vehicles', FleetVehicleController::class);
        Route::put('vehicles/{vehicle}/toggle-active', [FleetVehicleController::class, 'toggleActive'])->name('vehicles.toggle-active');

        Route::resource('vehicle-types', FleetVehicleTypeController::class);
        Route::resource('assignments', FleetAssignmentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('station-vehicle-assignments', StationVehicleAssignmentController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Crew management
        Route::resource('crew-members', CrewMemberController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('crew-assignments', VehicleCrewAssignmentController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // =========================================
    // SHARED ROUTES - Available to all authenticated users
    // =========================================
    Route::middleware('role:admin,supervisor,seller,accountant,executive,fleet_manager')->group(function () {
        Route::get('/trips', [App\Http\Controllers\Api\TripController::class, 'index'])->name('trips.index');
        Route::get('/tickets', [App\Http\Controllers\Api\TicketController::class, 'index'])->name('tickets.index');

        // Settings landing page - read-only consultation for non-admin roles
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    });

    // Printing routes
    Route::get('/tickets/{ticket}/print', [TicketPrintController::class, 'print'])->name('tickets.print');
    Route::post('/tickets/print-multiple', [TicketPrintController::class, 'printMultiple'])->name('tickets.print-multiple');

    // Export routes
    Route::get('/tickets/export-pdf', [TicketPrintController::class, 'exportPdf'])->name('tickets.export-pdf');
});

require __DIR__.'/auth.php';
