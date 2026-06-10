<?php

namespace App\Http\Middleware;

use App\Support\HostScopedCookies;
use Closure;

class ConfigureHostScopedCookies
{
    public function handle($request, Closure $next)
    {
        config([
            'session.cookie' => HostScopedCookies::sessionCookieName($request->getHost()),
        ]);

        return $next($request);
    }
}
