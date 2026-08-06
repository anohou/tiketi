<?php

namespace App\Http\Middleware;

use App\Models\CrewMember;
use App\Models\Destination;
use App\Models\Route;
use App\Models\RouteFare;
use App\Models\Station;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Vehicle;
use App\Models\VehicleCrewAssignment;
use App\Models\VehicleType;
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
        $settingsStats = [];
        $isTenant = function_exists('tenancy') && tenancy()->initialized;

        if ($user && $isTenant) {
            $assignedStations = UserStationAssignment::where('user_id', $user->id)
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

            if ($user->role === 'supervisor') {
                $stationIds = $user->getActiveStationIds();
                $settingsStats = [
                    'stations' => count($stationIds),
                    'destinations' => 0,
                    'routes' => 0,
                    'vehicles' => 0,
                    'vehicleTypes' => 0,
                    'trips' => 0,
                    'fares' => 0,
                    'users' => User::where('role', 'seller')
                        ->where(function ($q) use ($stationIds) {
                            $q->whereHas('stationAssignments', function ($sq) use ($stationIds) {
                                $sq->whereIn('station_id', $stationIds)->where('active', true);
                            })
                                ->orWhere(function ($sq) {
                                    $sq->whereDoesntHave('stationAssignments')
                                        ->where('settings->creator_id', auth()->id());
                                });
                        })->count(),
                    'assignments' => UserStationAssignment::whereIn('station_id', $stationIds)
                        ->whereHas('user', function ($q) {
                            $q->where('role', 'seller');
                        })->count(),
                    'crewMembers' => 0,
                    'crewAssignments' => 0,
                ];
            } else {
                $settingsStats = [
                    'stations' => Station::count(),
                    'destinations' => Destination::count(),
                    'routes' => Route::count(),
                    'vehicles' => Vehicle::count(),
                    'vehicleTypes' => VehicleType::count(),
                    'trips' => Trip::count(),
                    'fares' => RouteFare::count(),
                    'users' => User::count(),
                    'assignments' => UserStationAssignment::count(),
                    'crewMembers' => CrewMember::count(),
                    'crewAssignments' => VehicleCrewAssignment::count(),
                ];
            }
        }

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user ? ($isTenant ? $user->load('stationAssignments') : $user) : null,
            ],
            'isTenant' => $isTenant,
            'settingsStats' => $settingsStats,
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
                'created_user_password' => fn () => $request->session()->get('created_user_password'),
                'tenant_admin_password' => fn () => $request->session()->get('tenant_admin_password'),
                'ticket_id' => fn () => $request->session()->get('flash.ticket_id'),
                'ticket_ids' => fn () => $request->session()->get('flash.ticket_ids'),
            ],
        ];
    }
}
