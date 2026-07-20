<?php

namespace App\Providers;

use App\Events\SeatMapUpdated;
use App\Events\TidsUpdated;
use App\Events\TripCreated;
use App\Models\CrewMember;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/landlord_migrations');

        Vite::prefetch(concurrency: 3);

        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid): bool {
            if (! $isValid || ! ($accessToken->tokenable instanceof CrewMember)) {
                return $isValid;
            }

            $lastActivity = $accessToken->last_used_at ?? $accessToken->created_at;
            $inactivityDays = (int) config('transport.crew_auth.token_inactivity_days', 14);

            return $inactivityDays <= 0 || $lastActivity->gt(now()->subDays($inactivityDays));
        });

        RateLimiter::for('public-catalog', function (Request $request) {
            $tenant = tenancy()->initialized ? (string) tenant('id') : 'central';

            return Limit::perMinute(60)->by($tenant.':'.$request->ip());
        });

        RateLimiter::for('operational-api', function (Request $request) {
            $tenant = tenancy()->initialized ? (string) tenant('id') : 'central';
            $actor = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(120)->by($tenant.':'.$actor);
        });

        Event::listen(TripCreated::class, function (TripCreated $event): void {
            try {
                TidsUpdated::dispatch($event->trip, 'trip.created');
            } catch (\Throwable $exception) {
                Log::warning('Échec de diffusion TIDS après création du voyage.', [
                    'trip_id' => $event->trip->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        Event::listen(SeatMapUpdated::class, function (SeatMapUpdated $event): void {
            try {
                TidsUpdated::dispatch($event->trip, $event->action);
            } catch (\Throwable $exception) {
                Log::warning('Échec de diffusion de la mise à jour TIDS.', [
                    'trip_id' => $event->trip->id,
                    'action' => $event->action,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        // Removed forced root URL to allow multi-tenancy to detect correct domain
    }
}
