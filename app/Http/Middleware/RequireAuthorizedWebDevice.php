<?php

namespace App\Http\Middleware;

use App\Models\AuthorizedDevice;
use App\Services\DeviceAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthorizedWebDevice
{
    public function __construct(private readonly DeviceAccessService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->devices->isEnabled(AuthorizedDevice::CHANNEL_WEB)) {
            return $next($request);
        }

        if ($this->devices->findApprovedWebDevice($request)) {
            return $next($request);
        }

        if ($request->user()) {
            $this->devices->requestWebDevice($request, $request->user());
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Cet appareil doit être autorisé par un administrateur.',
                'code' => 'DEVICE_APPROVAL_REQUIRED',
            ], 403);
        }

        return redirect('/')->withErrors([
            'email' => 'Cet appareil est en attente d’autorisation par un administrateur.',
        ]);
    }
}
