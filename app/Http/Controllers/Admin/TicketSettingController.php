<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketSettingController extends Controller
{
    public function index()
    {
        $settings = TicketSetting::getSettings();

        return Inertia::render('Admin/TicketSettings/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'phone_numbers' => 'nullable|array',
            'phone_numbers.*' => 'string|max:255',
            'cc_label' => 'nullable|string|max:255',
            'footer_messages' => 'nullable|array',
            'footer_messages.*' => 'string|max:255',
            'baggage_policy_message' => 'nullable|string|max:1000',
            'print_qr_code' => 'boolean',
        ]);

        $settings = TicketSetting::getSettings();
        $settings->update($validated);

        return back()->with('success', 'Paramètres d\'impression mis à jour avec succès.');
    }
}
