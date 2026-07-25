<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TicketSettingController extends Controller
{
    public function index()
    {
        $settings = TicketSetting::getSettings();
        $previewTicket = Ticket::with([
            'trip.route',
            'trip.vehicle',
            'fromStation',
            'toStation',
        ])->latest()->first();

        return Inertia::render('Admin/TicketSettings/Index', [
            'settings' => $settings,
            'previewTicket' => $previewTicket,
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
            'baggage_policy_message_2' => 'nullable|string|max:1000',
            'print_qr_code' => 'boolean',
        ]);

        $settings = TicketSetting::getSettings();

        $settingsData = $settings->settings ?? [];
        if ($request->has('baggage_policy_message_2')) {
            $settingsData['baggage_policy_message_2'] = $validated['baggage_policy_message_2'];
        }

        $settings->update(array_merge(
            collect($validated)->except('baggage_policy_message_2')->all(),
            ['settings' => $settingsData]
        ));

        return back()->with('success', 'Paramètres d\'impression mis à jour avec succès.');
    }
}
