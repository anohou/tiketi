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
        
        // Get assigned stations for the current user
        $assignedStations = [];
        if ($user) {
            $assignedStations = \App\Models\UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->with('station')
                ->get()
                ->map(function($assignment) {
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
                'user' => $user ? $user->load('stationAssignments') : null,
            ],
            'assignedStations' => $assignedStations,
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'ticket_id' => fn () => $request->session()->get('flash.ticket_id'),
                'ticket_ids' => fn () => $request->session()->get('flash.ticket_ids'),
            ],
        ];
    }
}
