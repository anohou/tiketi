<?php

use App\Http\Controllers\Landlord\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Landlord (Central) Routes
|--------------------------------------------------------------------------
|
| These routes are for the central/admin application that manages tenants.
| They run on central domains (localhost, admin.transport.ci, etc.)
|
*/

// Central admin routes - manage tenants
Route::middleware(['web', 'auth'])->prefix('landlord')->name('landlord.')->group(function () {
    // Tenant Management
    Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
    Route::get('/tenants/{tenant}/edit', [TenantController::class, 'edit'])->name('tenants.edit');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])->name('tenants.destroy');

    // Domain Management for a tenant
    Route::post('/tenants/{tenant}/domains', [TenantController::class, 'addDomain'])->name('tenants.domains.store');
    Route::delete('/tenants/{tenant}/domains/{domain}', [TenantController::class, 'removeDomain'])->name('tenants.domains.destroy');
});
