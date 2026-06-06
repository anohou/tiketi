<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LoyaltySettingController extends Controller
{
    public function index()
    {
        $settings = TicketSetting::getSettings();

        return Inertia::render('Admin/Settings/Loyalty', [
            'settings' => [
                'okohi_url' => $settings->okohi_url,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'okohi_url' => 'nullable|string|max:500',
        ]);

        TicketSetting::getSettings()->update($validated);

        return back()->with('success', 'Paramètres de fidélisation enregistrés.');
    }
}
