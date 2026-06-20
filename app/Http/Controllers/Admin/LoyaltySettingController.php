<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Inertia\Inertia;

class LoyaltySettingController extends Controller
{
    public function index()
    {
        $settings = TicketSetting::getSettings();

        return Inertia::render('Admin/Settings/Loyalty', [
            'settings' => [
                'okohi_base_url' => $settings->okohi_base_url,
                'okohi_integration_url' => $settings->okohi_integration_url,
            ],
        ]);
    }
}
