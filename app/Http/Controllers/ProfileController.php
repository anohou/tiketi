<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\UserStationAssignment;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $isTenant = function_exists('tenancy') && tenancy()->initialized;

        // Only read tenant-owned station assignments when tenancy is active
        // and the table exists in the current connection.
        $assignedStations = collect();
        if ($user && $isTenant && Schema::hasTable('user_station_assignments')) {
            $assignedStations = UserStationAssignment::where('user_id', $user->id)
                ->where('active', true)
                ->with('station')
                ->get()
                ->map(function ($assignment) {
                    return [
                        'id' => $assignment->station->id,
                        'name' => $assignment->station->name,
                        'assigned_at' => $assignment->created_at->format('d/m/Y'),
                    ];
                });
        }

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'assignedStations' => $assignedStations,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
