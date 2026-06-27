<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

class UniversalTenancy extends InitializeTenancyByDomain
{
    public function handle($request, Closure $next)
    {
        static::$onFail ??= function ($exception, Request $request) {
            if ($redirect = self::redirectForWwwCentralDomain($request)) {
                return $redirect;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Tenant not found for this domain.',
                ], 404);
            }

            abort(404);
        };

        // Explicitly check for central domains and bypass tenancy if matched.
        // This fixes issues where the package's internal check might fail or throw exceptions unexpectedly.
        if ($redirect = self::redirectForWwwCentralDomain($request)) {
            return $redirect;
        }

        $host = $request->getHost();
        $centralDomains = self::centralDomains();

        // Bypass tenancy pour les routes Okohi — appelées via IP ou domaine central
        if (str_starts_with($request->getPathInfo(), '/api/okohi/')) {
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

    /**
     * @return list<string>
     */
    private static function centralDomains(): array
    {
        $domains = config('tenancy.central_domains', []);

        if (! is_array($domains)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($domain) => trim(strtolower((string) $domain)),
            $domains
        ))));
    }

    private static function redirectForWwwCentralDomain(Request $request)
    {
        $host = strtolower($request->getHost());

        foreach (self::centralDomains() as $centralDomain) {
            if ($host === 'www.'.$centralDomain) {
                return redirect()->to(
                    $request->getScheme().'://'.$centralDomain.$request->getRequestUri(),
                    301
                );
            }
        }

        return null;
    }
}
