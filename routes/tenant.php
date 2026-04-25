<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenancyServiceProvider.
|
| This uses hybrid identification - supports both:
| - Custom domains: alpha-express.com
| - Subdomains: beta.transport.ci
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    // Include all existing web routes in tenant context
    // require base_path('routes/web.php');
    // Note: web.php is now loaded globally in bootstrap/app.php with universal tenancy middleware
});
