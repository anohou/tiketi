<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index()
    {
        $query = User::with(['stationAssignments.station'])
            ->withCount('soldTickets as sales_count')
            ->withSum('soldTickets as sales_total', 'amount_collected')
            ->withMax('soldTickets as last_sale_at', 'created_at');

        if (auth()->user()->role === 'supervisor') {
            $stationIds = auth()->user()->getActiveStationIds();
            $query->where('role', 'seller')
                ->where(function ($q) use ($stationIds) {
                    $q->whereHas('stationAssignments', function ($sq) use ($stationIds) {
                        $sq->whereIn('station_id', $stationIds)->where('active', true);
                    })
                        ->orWhere(function ($sq) {
                            $sq->whereDoesntHave('stationAssignments')
                                ->where('settings->creator_id', auth()->id());
                        });
                });
        }

        $users = $query->orderBy('name')->paginate(20);

        $stationsQuery = Station::orderBy('name');
        if (auth()->user()->role === 'supervisor') {
            $stationsQuery->whereIn('id', auth()->user()->getActiveStationIds());
        }
        $stations = $stationsQuery->get(['id', 'name', 'city']);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'stations' => $stations,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Users/Form');
    }

    public function store(Request $request)
    {
        $password = Str::password(10, true, true, false, false);
        $currentUser = auth()->user();

        if ($currentUser->role === 'supervisor') {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'telephone' => 'required|string|max:20',
            ]);
            $role = 'seller';
        } else {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'telephone' => 'required|string|max:20',
                'role' => 'required|in:admin,supervisor,seller,accountant,executive,fleet_manager',
            ]);
            $role = $request->role;
        }

        User::create([
            'name' => $request->name,
            'email' => $request->email,
            'telephone' => $request->telephone,
            'role' => $role,
            'password' => Hash::make($password),
            'settings' => $currentUser->role === 'supervisor' ? ['creator_id' => $currentUser->id] : null,
        ]);

        $redirectRoute = $currentUser->role === 'supervisor' ? 'supervisor.users.index' : 'admin.users.index';

        return redirect()->route($redirectRoute)
            ->with('success', 'Utilisateur créé avec succès.')
            ->with('created_user_password', $password);
    }

    public function show(User $user)
    {
        $redirectRoute = auth()->user()->role === 'supervisor' ? 'supervisor.users.edit' : 'admin.users.edit';

        return redirect()->route($redirectRoute, $user);
    }

    public function edit(User $user)
    {
        $currentUser = auth()->user();
        if ($currentUser->role === 'supervisor') {
            if ($user->role !== 'seller') {
                abort(403, 'Unauthorized action.');
            }
            $stationIds = $currentUser->getActiveStationIds();
            $hasCommonStation = $user->stationAssignments()->whereIn('station_id', $stationIds)->where('active', true)->exists();
            $isCreatedByMe = ($user->settings['creator_id'] ?? null) === $currentUser->id;

            if (! $hasCommonStation && ! $isCreatedByMe) {
                abort(403, 'This user is outside your perimeter.');
            }
        }

        return Inertia::render('Admin/Users/Form', [
            'user' => $user,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();

        if ($currentUser->role === 'supervisor') {
            if ($user->role !== 'seller') {
                abort(403, 'Unauthorized action.');
            }
            $stationIds = $currentUser->getActiveStationIds();
            $hasCommonStation = $user->stationAssignments()->whereIn('station_id', $stationIds)->where('active', true)->exists();
            $isCreatedByMe = ($user->settings['creator_id'] ?? null) === $currentUser->id;

            if (! $hasCommonStation && ! $isCreatedByMe) {
                abort(403, 'This user is outside your perimeter.');
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
                'telephone' => 'required|string|max:20',
                'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            ]);
            $role = 'seller';
        } else {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
                'telephone' => 'required|string|max:20',
                'role' => 'required|in:admin,supervisor,seller,accountant,executive,fleet_manager',
                'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            ]);
            $role = $request->role;
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'telephone' => $request->telephone,
            'role' => $role,
        ]);

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        $redirectRoute = $currentUser->role === 'supervisor' ? 'supervisor.users.index' : 'admin.users.index';

        return redirect()->route($redirectRoute)->with('success', 'Utilisateur mis à jour avec succès.');
    }

    public function toggleActive(User $user)
    {
        $currentUser = auth()->user();
        if ($user->id === $currentUser->id) {
            return back()->with('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        }

        if ($currentUser->role === 'supervisor') {
            if ($user->role !== 'seller') {
                abort(403, 'Unauthorized action.');
            }
            $stationIds = $currentUser->getActiveStationIds();
            $hasCommonStation = $user->stationAssignments()->whereIn('station_id', $stationIds)->where('active', true)->exists();
            $isCreatedByMe = ($user->settings['creator_id'] ?? null) === $currentUser->id;

            if (! $hasCommonStation && ! $isCreatedByMe) {
                abort(403, 'This user is outside your perimeter.');
            }
        }

        $user->update(['active' => ! $user->active]);

        return back()->with('success', $user->active ? 'Utilisateur activé.' : 'Utilisateur désactivé.');
    }

    public function destroy(User $user)
    {
        $currentUser = auth()->user();
        if ($user->id === $currentUser->id) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        if ($currentUser->role === 'supervisor') {
            if ($user->role !== 'seller') {
                abort(403, 'Unauthorized action.');
            }
            $stationIds = $currentUser->getActiveStationIds();
            $hasCommonStation = $user->stationAssignments()->whereIn('station_id', $stationIds)->where('active', true)->exists();
            $isCreatedByMe = ($user->settings['creator_id'] ?? null) === $currentUser->id;

            if (! $hasCommonStation && ! $isCreatedByMe) {
                abort(403, 'This user is outside your perimeter.');
            }
        }

        $user->delete();

        return back()->with('success', 'Utilisateur supprimé avec succès.');
    }
}
