<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuthorizedDevice;
use App\Services\DeviceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly DeviceAccessService $devices) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        if ($this->devices->isEnabled(AuthorizedDevice::CHANNEL_WEB)
            && ! $this->devices->findApprovedWebDevice($request)) {
            $device = $this->devices->requestWebDevice($request, $request->user());
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => $device->status === AuthorizedDevice::STATUS_REVOKED
                    ? 'Cet appareil a été révoqué par un administrateur.'
                    : 'Cet appareil est en attente d’autorisation par un administrateur.',
            ]);
        }

        $request->session()->regenerate();

        if (in_array($request->user()->role, ['superadmin', 'super_admin'], true)) {
            return redirect()->route('landlord.tenants.index');
        }

        if ($request->user()->role === 'fleet_manager') {
            return redirect()->route('fleet.dashboard');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
