<?php

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
    Route::get('/tenants', [\App\Http\Controllers\Landlord\TenantController::class, 'index'])->name('tenants.index');
    Route::get('/tenants/create', [\App\Http\Controllers\Landlord\TenantController::class, 'create'])->name('tenants.create');
    Route::post('/tenants', [\App\Http\Controllers\Landlord\TenantController::class, 'store'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [\App\Http\Controllers\Landlord\TenantController::class, 'show'])->name('tenants.show');
    Route::get('/tenants/{tenant}/edit', [\App\Http\Controllers\Landlord\TenantController::class, 'edit'])->name('tenants.edit');
    Route::put('/tenants/{tenant}', [\App\Http\Controllers\Landlord\TenantController::class, 'update'])->name('tenants.update');
    Route::delete('/tenants/{tenant}', [\App\Http\Controllers\Landlord\TenantController::class, 'destroy'])->name('tenants.destroy');
    
    // Domain Management for a tenant
    Route::post('/tenants/{tenant}/domains', [\App\Http\Controllers\Landlord\TenantController::class, 'addDomain'])->name('tenants.domains.store');
    Route::delete('/tenants/{tenant}/domains/{domain}', [\App\Http\Controllers\Landlord\TenantController::class, 'removeDomain'])->name('tenants.domains.destroy');
});
