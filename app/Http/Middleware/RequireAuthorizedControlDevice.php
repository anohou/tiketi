<?php

namespace App\Http\Middleware;

use App\Models\AuthorizedDevice;
use App\Services\DeviceAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthorizedControlDevice
{
    public function __construct(private readonly DeviceAccessService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->devices->isEnabled(AuthorizedDevice::CHANNEL_CONTROL)) {
            return $next($request);
        }

        $token = $request->user()?->currentAccessToken();
        $device = $token?->authorized_device_id
            ? AuthorizedDevice::query()
                ->whereKey($token->authorized_device_id)
                ->where('channel', AuthorizedDevice::CHANNEL_CONTROL)
                ->where('status', AuthorizedDevice::STATUS_APPROVED)
                ->first()
            : null;

        if (! $device) {
            $token?->delete();

            return response()->json([
                'message' => 'Cet appareil n’est plus autorisé.',
                'code' => 'DEVICE_REVOKED',
            ], 403);
        }

        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(5))) {
            $device->update([
                'last_seen_at' => now(),
                'last_ip' => $request->ip(),
                'last_user_agent' => $request->userAgent(),
            ]);
        }

        return $next($request);
    }
}
