<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Get assigned stations for the current user (only if tenancy is initialized)
        $assignedStations = [];
        $isTenant = function_exists('tenancy') && tenancy()->initialized;

        if ($user && $isTenant) {
            $assignedStations = \App\Models\UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->with('station')
                ->get()
                ->map(function ($assignment) {
                    return [
                        'id' => $assignment->station->id,
                        'name' => $assignment->station->name,
                    ];
                })
                ->toArray();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ($isTenant ? $user->load('stationAssignments') : $user) : null,
            ],
            'isTenant' => $isTenant,
            'tenant' => $isTenant ? [
                'id' => tenant('id'),
                'name' => tenant('name'),
                'logo_url' => tenant('logo_url'),
            ] : null,
            'assignedStations' => $assignedStations,
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'tenant_admin_password' => fn () => $request->session()->get('tenant_admin_password'),
                'ticket_id' => fn () => $request->session()->get('flash.ticket_id'),
                'ticket_ids' => fn () => $request->session()->get('flash.ticket_ids'),
            ],
        ];
    }
}
