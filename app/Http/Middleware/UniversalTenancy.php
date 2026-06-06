<?php

namespace App\Http\Middleware;

use Closure;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

class UniversalTenancy extends InitializeTenancyByDomain
{
    public function handle($request, Closure $next)
    {
        // Explicitly check for central domains and bypass tenancy if matched.
        // This fixes issues where the package's internal check might fail or throw exceptions unexpectedly.

        $host = $request->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        // Bypass tenancy pour la route de vérification Okohi — accessible via IP ou domaine central
        if (str_contains($request->getPathInfo(), '/api/okohi/verify')) {
            return $next($request);
        }

        // Fallback for development if config is missing 'localhost'
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return $next($request);
        }

        // Bypass pour les adresses IP (appels externes Okohi)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $next($request);
        }

        if (in_array($host, $centralDomains)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
