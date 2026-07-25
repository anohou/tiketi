<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trips\CrewTripVisibility;
use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\CrewMember;
use App\Models\Trip;
use App\Services\DeviceAccessService;
use App\Services\OfflineCacheSigner;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CrewAuthController extends Controller
{
    public function __construct(private readonly DeviceAccessService $devices) {}

    public function login(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
            'pin' => ['required', 'string', 'regex:/^\d{4,12}$/'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'device_secret' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
            'device_platform' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:100'],
        ]);

        $phone = PhoneNumber::normalize($validated['phone']);
        $deviceFingerprint = $this->deviceFingerprint($request, $validated['device_id'] ?? null);
        $rateLimitKeys = $this->loginRateLimitKeys($request, $phone ?? $validated['phone'], $deviceFingerprint);
        $backoffKey = $rateLimitKeys['device'].':backoff';
        $maxAttempts = (int) config('transport.crew_auth.max_login_attempts', 5);

        if (RateLimiter::tooManyAttempts($rateLimitKeys['device'], $maxAttempts)
            || RateLimiter::tooManyAttempts($rateLimitKeys['phone_ip'], $maxAttempts)
            || RateLimiter::tooManyAttempts($backoffKey, 1)) {
            return response()->json([
                'message' => 'Trop de tentatives. Réessayez plus tard.',
                'retry_after' => max(
                    RateLimiter::availableIn($rateLimitKeys['device']),
                    RateLimiter::availableIn($rateLimitKeys['phone_ip']),
                    RateLimiter::availableIn($backoffKey),
                ),
            ], 429);
        }

        $crewMember = $phone ? CrewMember::query()->where('phone', $phone)->first() : null;

        // Compatibilité transitoire avec les numéros enregistrés avant leur canonicalisation.
        $crewMember ??= $phone
            ? CrewMember::query()->whereNotNull('phone')->get()
                ->first(fn (CrewMember $member) => PhoneNumber::normalize($member->phone) === $phone)
            : null;

        if (! $crewMember || ! $crewMember->active) {
            return $this->failedLogin($request, $rateLimitKeys, $backoffKey, $phone, $deviceFingerprint);
        }

        $storedPin = $crewMember->getRawOriginal('pin');
        if (! $storedPin || ! Hash::check($validated['pin'], $storedPin)) {
            return $this->failedLogin($request, $rateLimitKeys, $backoffKey, $phone, $deviceFingerprint);
        }

        foreach ([...array_values($rateLimitKeys), $backoffKey] as $key) {
            RateLimiter::clear($key);
        }

        if ($crewMember->phone !== $phone) {
            $crewMember->forceFill(['phone' => $phone])->save();
        }

        $authorizedDevice = null;
        if ($this->devices->isEnabled(AuthorizedDevice::CHANNEL_CONTROL)) {
            if (blank($validated['device_id'] ?? null)
                || ! Str::isUuid($validated['device_id'])
                || blank($validated['device_secret'] ?? null)) {
                return response()->json([
                    'message' => 'Cette version de TIKETI Control doit être mise à jour pour autoriser cet appareil.',
                    'code' => 'DEVICE_ID_REQUIRED',
                ], 403);
            }

            $authorizedDevice = $this->devices->requestControlDevice(
                $request,
                $crewMember,
                $validated['device_id'],
                strtolower($validated['device_secret']),
                $validated['device_name'] ?? null,
                $validated['device_platform'] ?? null,
                $validated['app_version'] ?? null,
            );

            if (! $authorizedDevice->isApproved()) {
                return response()->json([
                    'message' => match ($authorizedDevice->status) {
                        AuthorizedDevice::STATUS_REVOKED => 'Cet appareil a été révoqué par un administrateur.',
                        AuthorizedDevice::STATUS_REJECTED => 'Cet appareil a été refusé par un administrateur.',
                        default => 'Cet appareil est en attente d’autorisation par un administrateur.',
                    },
                    'code' => match ($authorizedDevice->status) {
                        AuthorizedDevice::STATUS_REVOKED => 'DEVICE_REVOKED',
                        AuthorizedDevice::STATUS_REJECTED => 'DEVICE_REJECTED',
                        default => 'DEVICE_APPROVAL_REQUIRED',
                    },
                    'request_id' => $authorizedDevice->id,
                ], 403);
            }
        }

        $tokenName = $this->tokenName($validated['device_name'] ?? 'Crew Mobile', $deviceFingerprint);
        $crewMember->tokens()->where('name', $tokenName)->delete();

        $expiresAt = now()->addDays((int) config('transport.crew_auth.token_expiration_days', 30));
        $token = $crewMember->createToken($tokenName, ['crew'], $expiresAt);
        if ($authorizedDevice) {
            $token->accessToken->forceFill(['authorized_device_id' => $authorizedDevice->id])->save();
        }

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'crew_member' => $this->crewPayload($crewMember),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    public function me(Request $request)
    {
        /** @var CrewMember $crewMember */
        $crewMember = $request->user();

        return response()->json([
            'crew_member' => $this->crewPayload($crewMember),
        ]);
    }

    public function sessions(Request $request)
    {
        $currentId = $request->user()?->currentAccessToken()?->getKey();
        $inactivityDays = (int) config('transport.crew_auth.token_inactivity_days', 14);
        $tokens = $request->user()->tokens()->latest()->get();
        [$activeTokens, $staleTokens] = $tokens->partition(function ($token) use ($inactivityDays) {
            $lastActivity = $token->last_used_at ?? $token->created_at;

            return ! ($token->expires_at?->isPast() ?? false)
                && ($inactivityDays <= 0 || $lastActivity->gt(now()->subDays($inactivityDays)));
        });
        $staleTokens->each->delete();

        return response()->json([
            'sessions' => $activeTokens->map(function ($token) use ($currentId, $inactivityDays) {
                $lastActivity = $token->last_used_at ?? $token->created_at;

                return [
                    'id' => $token->getKey(),
                    'name' => Str::before($token->name, '#'),
                    'current' => (string) $token->getKey() === (string) $currentId,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'inactive_expires_at' => $inactivityDays > 0 ? $lastActivity->copy()->addDays($inactivityDays)->toIso8601String() : null,
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function revokeSession(Request $request, int $tokenId)
    {
        $token = $request->user()->tokens()->findOrFail($tokenId);
        $token->delete();

        return response()->json(['message' => 'Session révoquée.']);
    }

    public function logoutOtherSessions(Request $request)
    {
        $currentId = $request->user()?->currentAccessToken()?->getKey();
        $request->user()->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json(['message' => 'Les autres sessions ont été révoquées.']);
    }

    private function failedLogin(Request $request, array $rateLimitKeys, string $backoffKey, ?string $phone, string $deviceFingerprint)
    {
        $attempt = RateLimiter::attempts($rateLimitKeys['phone_ip']) + 1;
        $lockoutSeconds = (int) config('transport.crew_auth.lockout_seconds', 60);
        RateLimiter::hit($rateLimitKeys['device'], $lockoutSeconds);
        RateLimiter::hit($rateLimitKeys['phone_ip'], $lockoutSeconds);

        $baseBackoff = (int) config('transport.crew_auth.login_backoff_base_seconds', 2);
        $maxBackoff = (int) config('transport.crew_auth.login_backoff_max_seconds', 60);
        $backoffSeconds = min($maxBackoff, $baseBackoff * (2 ** min($attempt - 1, 10)));
        if ($backoffSeconds > 0) {
            RateLimiter::hit($backoffKey, $backoffSeconds);
        }

        Log::warning('crew_login_failed', [
            'tenant_id' => tenancy()->initialized ? (string) tenant('id') : 'central',
            'phone_hash' => hash('sha256', $phone ?? 'invalid'),
            'device_hash' => $deviceFingerprint,
            'ip' => $request->ip(),
            'attempt' => $attempt,
        ]);

        return response()->json([
            'message' => 'Numéro ou code invalide.',
            'retry_after' => $backoffSeconds,
        ], 422);
    }

    private function loginRateLimitKeys(Request $request, string $phone, string $deviceFingerprint): array
    {
        $tenant = tenancy()->initialized ? (string) tenant('id') : 'central';
        $phoneHash = hash('sha256', $phone);
        $ipHash = hash('sha256', (string) $request->ip());

        return [
            'device' => "crew-login:{$tenant}:{$phoneHash}:{$ipHash}:{$deviceFingerprint}",
            'phone_ip' => "crew-login:{$tenant}:{$phoneHash}:{$ipHash}",
        ];
    }

    private function deviceFingerprint(Request $request, ?string $deviceId): string
    {
        $source = filled($deviceId) ? $deviceId : ($request->userAgent() ?? 'unknown').':'.$request->ip();

        return substr(hash('sha256', $source), 0, 24);
    }

    private function tokenName(string $deviceName, string $deviceFingerprint): string
    {
        $name = Str::limit(trim($deviceName) ?: 'Crew Mobile', 100, '');

        return $name.'#'.$deviceFingerprint;
    }

    private function crewPayload(CrewMember $crewMember): array
    {
        $crewMember->loadMissing([
            'currentAssignment.vehicle.vehicleType',
        ]);

        $currentAssignment = $crewMember->currentAssignment;
        $vehicle = $currentAssignment?->vehicle;

        $visibility = app(CrewTripVisibility::class);
        $query = Trip::with([
            'route.originStation',
            'route.destinationStation',
            'route.routeStopOrders.station',
            'vehicle.vehicleType',
            'tickets:id,trip_id,from_station_id,status',
        ]);
        $todayTrips = $visibility
            ->filter($visibility->apply($query, $crewMember)->orderBy('departure_at')->get(), $crewMember);

        return [
            'id' => $crewMember->id,
            'name' => $crewMember->name,
            'phone' => $crewMember->phone,
            'role' => $crewMember->role,
            'active' => $crewMember->active,
            'offline_cache_verification' => app(OfflineCacheSigner::class)->verificationDescriptor(),
            'tenant' => tenancy()->initialized ? [
                'id' => (string) tenant('id'),
                'name' => (string) tenant('name'),
                'logo_url' => tenant('logo_url') ? url((string) tenant('logo_url')) : null,
            ] : null,
            'current_assignment' => $currentAssignment ? [
                'id' => $currentAssignment->id,
                'role' => $currentAssignment->role,
                'assigned_from' => $currentAssignment->assigned_from?->toIso8601String(),
                'assigned_to' => $currentAssignment->assigned_to?->toIso8601String(),
                'vehicle' => $vehicle ? [
                    'id' => $vehicle->id,
                    'identifier' => $vehicle->identifier,
                    'maker' => $vehicle->maker,
                    'seat_count' => $vehicle->seat_count,
                    'vehicle_type' => $vehicle->vehicleType ? [
                        'id' => $vehicle->vehicleType->id,
                        'name' => $vehicle->vehicleType->name,
                        'seat_count' => $vehicle->vehicleType->seat_count,
                    ] : null,
                ] : null,
            ] : null,
            'today_trips' => $todayTrips->map(function ($trip) {
                $soldSeatsByStation = $trip->tickets
                    ->where('status', 'issued')
                    ->whereNotNull('from_station_id')
                    ->countBy('from_station_id');

                return [
                    'id' => $trip->id,
                    'code' => $trip->code,
                    'display_name' => $trip->display_name,
                    'departure_at' => $trip->departure_at?->toIso8601String(),
                    'status' => $trip->status,
                    'sales_control' => $trip->sales_control,
                    'driver' => $trip->driver ? [
                        'id' => $trip->driver->id,
                        'name' => $trip->driver->name,
                    ] : null,
                    'assistant' => $trip->assistant ? [
                        'id' => $trip->assistant->id,
                        'name' => $trip->assistant->name,
                    ] : null,
                    'origin_station' => $trip->originStation ? [
                        'id' => $trip->originStation->id,
                        'name' => $trip->originStation->name,
                    ] : null,
                    'destination_station' => $trip->destinationStation ? [
                        'id' => $trip->destinationStation->id,
                        'name' => $trip->destinationStation->name,
                    ] : null,
                    'route' => $trip->route ? [
                        'id' => $trip->route->id,
                        'name' => $trip->route->name,
                        'route_stop_orders' => $trip->route->routeStopOrders
                            ->sortBy('stop_index')
                            ->values()
                            ->map(fn ($stop) => [
                                'id' => $stop->id,
                                'station_id' => $stop->station_id,
                                'stop_index' => $stop->stop_index,
                                'sold_seats_count' => $soldSeatsByStation->get($stop->station_id, 0),
                                'station' => $stop->station ? [
                                    'id' => $stop->station->id,
                                    'name' => $stop->station->name,
                                ] : null,
                            ])->all(),
                    ] : null,
                    'vehicle' => [
                        'id' => $trip->vehicle?->id,
                        'identifier' => $trip->vehicle?->identifier,
                        'seat_count' => $trip->total_seats,
                    ],
                    'total_seats' => $trip->total_seats,
                    'available_seats' => $trip->available_seats,
                    'occupied_seats_count' => $trip->occupied_seats_count,
                    'sold_tickets_count' => $trip->sold_tickets_count,
                ];
            })->values(),
        ];
    }
}
