<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrewMember;
use App\Models\Destination;
use App\Models\OperationalSetting;
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
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Settings/Index', [
            'stats' => $this->settingsStats(),
            'operationalSettings' => OperationalSetting::current(),
        ]);
    }

    public function enterprise()
    {
        return Inertia::render('Admin/Settings/Enterprise', [
            'tenant' => tenant(),
            'stats' => $this->settingsStats(),
            'operationalSettings' => OperationalSetting::current(),
        ]);
    }

    public function updateEnterprise(Request $request)
    {
        $tenant = tenant();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'logo' => 'nullable|file|mimetypes:image/png,image/jpeg,image/webp,image/svg+xml|max:5120',
            'automatic_connection_allocation' => 'required|boolean',
            'connection_transfer_buffer_minutes' => 'required|integer|min:0|max:240',
            'seller_compensation_enabled' => 'required|boolean',
            'seller_compensation_max_amount' => 'required|integer|min:0',
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $directory = public_path('logos');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Delete old logo if exists
            if ($tenant->logo_url) {
                $oldPath = public_path(ltrim(str_replace('/logos/', 'logos/', $tenant->logo_url), '/'));
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $filename = time().'_'.$file->getClientOriginalName();
            $file->move($directory, $filename);
            $tenant->logo_url = '/logos/'.$filename;
        }

        $tenant->name = $request->name;
        $tenant->email = $request->email;
        $tenant->phone = $request->phone;

        $tenant->save();

        $operationalSettings = OperationalSetting::current();
        $operationalSettings->update([
            'automatic_connection_allocation' => $request->boolean('automatic_connection_allocation'),
            'connection_transfer_buffer_minutes' => (int) $request->input('connection_transfer_buffer_minutes', 15),
            'settings' => array_merge($operationalSettings->settings ?? [], [
                'seller_compensation_enabled' => $request->boolean('seller_compensation_enabled'),
                'seller_compensation_max_amount' => (int) $request->input('seller_compensation_max_amount', 0),
            ]),
        ]);

        return redirect()->back()->with('success', 'Paramètres de l\'entreprise mis à jour.');
    }

    private function settingsStats(): array
    {
        $user = auth()->user();
        if ($user && $user->role === 'supervisor') {
            $stationIds = $user->getActiveStationIds();

            return [
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
        }

        return [
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
