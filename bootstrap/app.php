<?php

use App\Http\Middleware\ConfigureHostScopedCookies;
use App\Http\Middleware\CrewMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectCrewToken;
use App\Http\Middleware\RequireAuthorizedControlDevice;
use App\Http\Middleware\RequireAuthorizedWebDevice;
use App\Http\Middleware\RequireInitializedTenancy;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\UniversalTenancy;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Load landlord routes for central domain ONLY (tenant management)
            $centralDomains = config('tenancy.central_domains', []);

            foreach ($centralDomains as $domain) {
                Route::middleware('web')
                    ->domain($domain)
                    ->group(base_path('routes/landlord.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            UniversalTenancy::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            UniversalTenancy::class,
        ]);

        $middleware->web(prepend: [
            ConfigureHostScopedCookies::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AddQueuedCookiesToResponse::class,
            VerifyCsrfToken::class,
        ]);

        // Trust proxies globally (Traefik)
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'crew' => CrewMiddleware::class,
            'non_crew' => RejectCrewToken::class,
            'role' => RoleMiddleware::class,
            'tenant.initialized' => RequireInitializedTenancy::class,
            'authorized.control.device' => RequireAuthorizedControlDevice::class,
            'authorized.web.device' => RequireAuthorizedWebDevice::class,
            'okohi.signature' => \App\Http\Middleware\OkohiSignatureVerification::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 403 && request()->inertia()) {
                return Inertia::render('Error', [
                    'status' => 403,
                    'message' => $response->exception->getMessage() ?: 'Accès refusé.',
                ])->toResponse(request())->setStatusCode(403);
            }

            return $response;
        });
    })->create();
