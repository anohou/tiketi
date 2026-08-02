<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Station;
use App\Models\User;
use App\Models\UserRouteAssignment;
use App\Models\UserStationAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class UserAssignmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'station_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $currentUser = auth()->user();
        $stationIds = $currentUser->getActiveStationIds();

        $query = UserStationAssignment::query()
            ->with(['user', 'station'])
            ->when($filters['station_id'] ?? null, fn (Builder $query, string $stationId) => $query->where('station_id', $stationId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, string $userId) => $query->where('user_id', $userId))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('active', false))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $term = '%'.trim($search).'%';
                $query->where(function (Builder $searchQuery) use ($term) {
                    $searchQuery
                        ->whereHas('user', fn (Builder $user) => $user
                            ->whereLike('name', $term, caseSensitive: false)
                            ->orWhereLike('email', $term, caseSensitive: false))
                        ->orWhereHas('station', fn (Builder $station) => $station
                            ->whereLike('name', $term, caseSensitive: false)
                            ->orWhereLike('city', $term, caseSensitive: false)
                            ->orWhereLike('code', $term, caseSensitive: false));
                });
            });
        if ($currentUser->role === 'supervisor') {
            $query->whereIn('station_id', $stationIds)
                ->whereHas('user', function ($q) {
                    $q->where('role', 'seller');
                });
        }

        $assignments = $query->orderBy('created_at', 'desc')->paginate(40)->withQueryString();
        $assignments->getCollection()->each(function ($assignment) {
            $assignment->route_ids = UserRouteAssignment::where('user_id', $assignment->user_id)
                ->where('station_id', $assignment->station_id)
                ->where('active', true)
                ->pluck('route_id')
                ->toArray();
        });

        if ($currentUser->role === 'supervisor') {
            $users = User::where('role', 'seller')
                ->where(function ($q) use ($stationIds) {
                    $q->whereHas('stationAssignments', function ($sq) use ($stationIds) {
                        $sq->whereIn('station_id', $stationIds)->where('active', true);
                    })
                        ->orWhere(function ($sq) {
                            $sq->whereDoesntHave('stationAssignments')
                                ->where('settings->creator_id', auth()->id());
                        });
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);

            $stations = Station::where('active', true)
                ->whereIn('id', $stationIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'city']);

            $routes = Route::with(['originStation', 'destinationStation', 'routeStopOrders'])
                ->where('active', true)
                ->where(function ($q) use ($stationIds) {
                    $q->whereIn('origin_station_id', $stationIds)
                        ->orWhereIn('destination_station_id', $stationIds)
                        ->orWhereHas('routeStopOrders', function ($sq) use ($stationIds) {
                            $sq->whereIn('station_id', $stationIds);
                        });
                })
                ->orderBy('name')
                ->get();
        } else {
            $users = User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name', 'email', 'role']);
            $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'city']);
            $routes = Route::with(['originStation', 'destinationStation', 'routeStopOrders'])->where('active', true)->orderBy('name')->get();
        }

        return Inertia::render('Admin/Assignments/Index', [
            'assignments' => $assignments,
            'users' => $users,
            'stations' => $stations,
            'routes' => $routes,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'station_id' => $filters['station_id'] ?? '',
                'user_id' => $filters['user_id'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $currentUser = auth()->user();
        if ($currentUser->role === 'supervisor') {
            $stationIds = $currentUser->getActiveStationIds();
            $users = User::where('role', 'seller')
                ->where(function ($q) use ($stationIds) {
                    $q->whereHas('stationAssignments', function ($sq) use ($stationIds) {
                        $sq->whereIn('station_id', $stationIds)->where('active', true);
                    })
                        ->orWhere(function ($sq) {
                            $sq->whereDoesntHave('stationAssignments')
                                ->where('settings->creator_id', auth()->id());
                        });
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
            $stations = Station::where('active', true)->whereIn('id', $stationIds)->orderBy('name')->get(['id', 'name', 'code', 'city']);
        } else {
            $users = User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name', 'email', 'role']);
            $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'city']);
        }

        return Inertia::render('Admin/Assignments/Form', [
            'users' => $users,
            'stations' => $stations,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'station_id' => 'required|uuid|exists:stations,id',
            'active' => 'boolean',
            'route_ids' => 'nullable|array',
            'route_ids.*' => 'uuid|exists:routes,id',
        ]);

        $currentUser = auth()->user();
        if ($currentUser->role === 'supervisor') {
            $stationIds = $currentUser->getActiveStationIds();
            if (! in_array($data['station_id'], $stationIds)) {
                abort(403, 'Unauthorized station assignment.');
            }
            $targetUser = User::findOrFail($data['user_id']);
            if ($targetUser->role !== 'seller') {
                abort(403, 'You can only assign sellers.');
            }
            $isCreatedByMe = ($targetUser->settings['creator_id'] ?? null) === $currentUser->id;
            $hasCommonStation = $targetUser->stationAssignments()->whereIn('station_id', $stationIds)->where('active', true)->exists();
            if (! $isCreatedByMe && ! $hasCommonStation) {
                abort(403, 'This user is outside your perimeter.');
            }
        }

        // Check if assignment already exists
        $existing = UserStationAssignment::where('user_id', $data['user_id'])
            ->where('station_id', $data['station_id'])
            ->first();

        if ($existing) {
            return back()->withErrors(['station_id' => 'Cet utilisateur est déjà affecté à cette gare.']);
        }

        DB::transaction(function () use ($data) {
            UserStationAssignment::create([
                'user_id' => $data['user_id'],
                'station_id' => $data['station_id'],
                'active' => $data['active'] ?? true,
            ]);

            if (! empty($data['route_ids'])) {
                foreach ($data['route_ids'] as $routeId) {
                    UserRouteAssignment::create([
                        'user_id' => $data['user_id'],
                        'station_id' => $data['station_id'],
                        'route_id' => $routeId,
                        'active' => true,
                    ]);
                }
            }
        });

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(UserStationAssignment $assignment)
    {
        $redirectRoute = auth()->user()->role === 'supervisor' ? 'supervisor.assignments.edit' : 'admin.assignments.edit';

        return redirect()->route($redirectRoute, $assignment);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UserStationAssignment $assignment)
    {
        $currentUser = auth()->user();
        if ($currentUser->role === 'supervisor') {
            $stationIds = $currentUser->getActiveStationIds();
            if (! in_array($assignment->station_id, $stationIds)) {
                abort(403, 'Unauthorized action.');
            }
            $users = User::where('role', 'seller')
                ->where(function ($q) use ($stationIds) {
                    $q->whereHas('stationAssignments', function ($sq) use ($stationIds) {
                        $sq->whereIn('station_id', $stationIds)->where('active', true);
                    })
                        ->orWhere(function ($sq) {
                            $sq->whereDoesntHave('stationAssignments')
                                ->where('settings->creator_id', auth()->id());
                        });
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
            $stations = Station::where('active', true)->whereIn('id', $stationIds)->orderBy('name')->get(['id', 'name', 'code', 'city']);
        } else {
            $users = User::whereIn('role', ['seller', 'supervisor'])->orderBy('name')->get(['id', 'name', 'email', 'role']);
            $stations = Station::where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'city']);
        }

        return Inertia::render('Admin/Assignments/Form', [
            'assignment' => $assignment->load(['user', 'station']),
            'users' => $users,
            'stations' => $stations,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UserStationAssignment $assignment)
    {
        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'station_id' => 'required|uuid|exists:stations,id',
            'active' => 'boolean',
            'route_ids' => 'nullable|array',
            'route_ids.*' => 'uuid|exists:routes,id',
        ]);

        $currentUser = auth()->user();
        if ($currentUser->role === 'supervisor') {
            $stationIds = $currentUser->getActiveStationIds();
            if (! in_array($data['station_id'], $stationIds) || ! in_array($assignment->station_id, $stationIds)) {
                abort(403, 'Unauthorized station assignment.');
            }
            $targetUser = User::findOrFail($data['user_id']);
            if ($targetUser->role !== 'seller') {
                abort(403, 'You can only assign sellers.');
            }
            $isCreatedByMe = ($targetUser->settings['creator_id'] ?? null) === $currentUser->id;
            $hasCommonStation = $targetUser->stationAssignments()->whereIn('station_id', $stationIds)->where('active', true)->exists();
            if (! $isCreatedByMe && ! $hasCommonStation) {
                abort(403, 'This user is outside your perimeter.');
            }
        }

        // Check if assignment already exists (excluding current)
        $existing = UserStationAssignment::where('user_id', $data['user_id'])
            ->where('station_id', $data['station_id'])
            ->where('id', '!=', $assignment->id)
            ->first();

        if ($existing) {
            return back()->withErrors(['station_id' => 'Cet utilisateur est déjà affecté à cette gare.']);
        }

        DB::transaction(function () use ($data, $assignment) {
            $oldUserId = $assignment->user_id;
            $oldStationId = $assignment->station_id;

            $assignment->update([
                'user_id' => $data['user_id'],
                'station_id' => $data['station_id'],
                'active' => $data['active'] ?? true,
            ]);

            // Clear old route assignments for this user and station
            UserRouteAssignment::where('user_id', $oldUserId)
                ->where('station_id', $oldStationId)
                ->delete();

            // Insert new ones
            if (! empty($data['route_ids'])) {
                foreach ($data['route_ids'] as $routeId) {
                    UserRouteAssignment::create([
                        'user_id' => $data['user_id'],
                        'station_id' => $data['station_id'],
                        'route_id' => $routeId,
                        'active' => true,
                    ]);
                }
            }
        });

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UserStationAssignment $assignment)
    {
        $currentUser = auth()->user();
        if ($currentUser->role === 'supervisor') {
            $stationIds = $currentUser->getActiveStationIds();
            if (! in_array($assignment->station_id, $stationIds) || $assignment->user->role !== 'seller') {
                abort(403, 'Unauthorized action.');
            }
        }

        DB::transaction(function () use ($assignment) {
            UserRouteAssignment::where('user_id', $assignment->user_id)
                ->where('station_id', $assignment->station_id)
                ->delete();

            $assignment->delete();
        });

        return back();
    }
}
