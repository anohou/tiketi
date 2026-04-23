<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\Route;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\Trip;
use App\Models\RouteFare;
use App\Models\User;
use App\Models\UserStationAssignment;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Settings/Index', [
            'stats' => [
                'stations' => Station::count(),
                'destinations' => \App\Models\Destination::count(),
                'routes' => Route::count(),

                'vehicles' => Vehicle::count(),
                'vehicleTypes' => VehicleType::count(),
                'trips' => Trip::count(),
                'fares' => RouteFare::count(),
                'users' => User::count(),
                'assignments' => UserStationAssignment::count(),
            ]
        ]);
    }

    public function enterprise()
    {
        return Inertia::render('Admin/Settings/Enterprise', [
            'tenant' => tenant()
        ]);
    }

    public function updateEnterprise(Request $request)
    {
        $tenant = tenant();
        
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'logo' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            
            // Delete old logo if exists
            if ($tenant->logo_url) {
                $oldPath = public_path(str_replace('/logos/', 'logos/', $tenant->logo_url));
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $filename = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('logos'), $filename);
            $tenant->logo_url = '/logos/' . $filename;
        }

        $tenant->name = $request->name;
        $tenant->email = $request->email;
        $tenant->phone = $request->phone;
        
        $tenant->save();

        return redirect()->back()->with('success', 'Paramètres de l\'entreprise mis à jour.');
    }
}
