<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Services\DeviceAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuthorizedDeviceController extends Controller
{
    public function __construct(private readonly DeviceAccessService $devices) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Devices', [
            'devices' => AuthorizedDevice::query()
                ->with(['approver:id,name', 'requester:id,name'])
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->latest('requested_at')
                ->get()
                ->map(fn (AuthorizedDevice $device) => [
                    'id' => $device->id,
                    'channel' => $device->channel,
                    'status' => $device->status,
                    'name' => $device->name,
                    'platform' => $device->platform,
                    'app_version' => $device->app_version,
                    'requested_by_type' => $device->requested_by_type,
                    'requested_by_id' => $device->requested_by_id,
                    'requester' => $device->requester?->only(['id', 'name']),
                    'approver' => $device->approver?->only(['id', 'name']),
                    'last_ip' => $device->last_ip,
                    'requested_at' => $device->requested_at?->toIso8601String(),
                    'approved_at' => $device->approved_at?->toIso8601String(),
                    'revoked_at' => $device->revoked_at?->toIso8601String(),
                    'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                ]),
            'restrictions' => [
                'web' => $this->devices->isEnabled(AuthorizedDevice::CHANNEL_WEB),
                'control' => $this->devices->isEnabled(AuthorizedDevice::CHANNEL_CONTROL),
            ],
        ]);
    }

    public function updateRestrictions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'web' => ['required', 'boolean'],
            'control' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            if ($validated['web']) {
                $device = $this->devices->requestWebDevice($request, $request->user());
                $this->devices->approve($device, $request->user());
            }

            $this->devices->setEnabled(AuthorizedDevice::CHANNEL_WEB, $validated['web']);
            $this->devices->setEnabled(AuthorizedDevice::CHANNEL_CONTROL, $validated['control']);
        });

        Log::notice('tenant_device_restrictions_updated', [
            'tenant_id' => tenancy()->initialized ? (string) tenant('id') : 'local',
            'admin_id' => $request->user()->id,
            'web' => $validated['web'],
            'control' => $validated['control'],
        ]);

        return back()->with('success', 'Restrictions des appareils mises à jour.');
    }

    public function update(Request $request, AuthorizedDevice $device): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'revoke'])],
        ]);

        match ($validated['action']) {
            'approve' => $this->devices->approve($device, $request->user()),
            'reject' => $device->update([
                'status' => AuthorizedDevice::STATUS_REJECTED,
                'approved_by_user_id' => $request->user()->id,
                'revoked_at' => now(),
            ]),
            'revoke' => $this->devices->revoke($device),
        };

        Log::notice('tenant_device_status_updated', [
            'tenant_id' => tenancy()->initialized ? (string) tenant('id') : 'local',
            'admin_id' => $request->user()->id,
            'device_id' => $device->id,
            'channel' => $device->channel,
            'action' => $validated['action'],
        ]);

        return back()->with('success', 'Statut de l’appareil mis à jour.');
    }
}
