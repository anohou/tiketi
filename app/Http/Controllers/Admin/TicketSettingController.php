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
            'qr_code_base_url' => 'nullable|url|max:255',
            'print_qr_code' => 'boolean',
            'okohi_enabled' => 'boolean',
            'okohi_host' => 'nullable|url|max:255',
            'okohi_company_id' => 'nullable|uuid',
            'okohi_loyalty_type' => 'nullable|in:points,visite',
            'okohi_integration_key' => 'nullable|string|max:255',
        ]);

        $settings = TicketSetting::getSettings();
        $settings->update($validated);

        return redirect()->back()->with('success', 'Paramètres mis à jour avec succès');
    }
}
