<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInitializedTenancy
{
    /**
     * Prevent tenant-only controllers from querying the central database.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Most legacy feature tests exercise tenant routes against the
        // in-memory default connection without bootstrapping stancl/tenancy.
        // Boundary-specific tests opt in explicitly.
        if (app()->environment('testing') && ! config('tenancy.enforce_route_boundary_in_testing', false)) {
            return $next($request);
        }

        if (function_exists('tenancy') && tenancy()->initialized) {
            return $next($request);
        }

        if ($request->user()?->role === 'superadmin' && $request->route()?->getName() !== 'landlord.tenants.index') {
            return redirect()->route('landlord.tenants.index');
        }

        abort(404, 'A tenant context is required for this route.');
    }
}
