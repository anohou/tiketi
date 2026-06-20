<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class OkohiConnectController extends Controller
{
    public function connect(Request $request)
    {
        $data = $request->validate([
            'okohi_base_url' => 'required|url|max:255',
            'code' => 'required|digits:4',
        ]);

        $verifyUrl = url('/api/okohi/verify');

        $response = Http::timeout(10)->post(
            rtrim($data['okohi_base_url'], '/').'/api/v1/partner-integrations/verify',
            [
                'code' => $data['code'],
                'verify_url' => $verifyUrl,
            ]
        );

        if (! $response->successful() || ! $response->json('success')) {
            throw ValidationException::withMessages([
                'code' => 'Code invalide ou expiré. Vérifiez le code et réessayez.',
            ]);
        }

        $integrationUrl = $response->json('okohi_integration_url');

        if (! $integrationUrl) {
            throw ValidationException::withMessages([
                'code' => 'Réponse Okohi invalide : URL d\'intégration manquante.',
            ]);
        }

        TicketSetting::getSettings()->update([
            'okohi_base_url' => $data['okohi_base_url'],
            'okohi_integration_url' => $integrationUrl,
        ]);

        return back()->with('success', 'Intégration Okohi activée avec succès.');
    }

    public function disconnect()
    {
        TicketSetting::getSettings()->update([
            'okohi_base_url' => null,
            'okohi_integration_url' => null,
        ]);

        return back()->with('success', 'Intégration Okohi désactivée.');
    }
}
