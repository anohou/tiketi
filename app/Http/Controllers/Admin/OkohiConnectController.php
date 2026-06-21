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
            'code' => 'required|digits:4',
        ]);

        $baseUrl = rtrim(config('services.okohi.base_url'), '/');

        if (! $baseUrl) {
            throw ValidationException::withMessages([
                'code' => 'OKOHI_BASE_URL non configuré sur ce serveur.',
            ]);
        }

        $appUrl = rtrim(config('app.url'), '/');
        $tenantId = tenant('id');

        $response = Http::timeout(10)->post(
            $baseUrl.'/api/v1/partner-integrations/verify',
            [
                'code' => $data['code'],
                'verify_url' => $appUrl.'/api/okohi/verify?tenant='.$tenantId.'&ticket_id={ticket_id}',
                'delete_url' => $appUrl.'/api/okohi/delete',
            ]
        );

        if (! $response->ok() || ! $response->json('success')) {
            $message = $response->status() === 404
                ? 'Code invalide ou déjà utilisé. Générez un nouveau code depuis Okohi.'
                : 'Code invalide ou expiré. Vérifiez le code et réessayez.';

            throw ValidationException::withMessages(['code' => $message]);
        }

        $integrationUrl = $response->json('okohi_integration_url');

        if (! $integrationUrl) {
            throw ValidationException::withMessages([
                'code' => "Réponse Okohi invalide : URL d'intégration manquante.",
            ]);
        }

        TicketSetting::getSettings()->update([
            'okohi_integration_url' => $integrationUrl,
            'okohi_integration_key' => self::extractIntegrationKey($integrationUrl),
        ]);

        return back()->with('success', 'Intégration Okohi activée avec succès.');
    }

    public function disconnect()
    {
        $settings = TicketSetting::getSettings();
        $key = $settings->okohi_integration_key;
        $baseUrl = rtrim(config('services.okohi.base_url'), '/');

        // Supprimer en local d'abord
        $settings->update([
            'okohi_integration_url' => null,
            'okohi_integration_key' => null,
        ]);

        // Notifier Okohi si on a la clé et l'URL de base
        if ($key && $baseUrl) {
            Http::timeout(10)
                ->withHeaders(['X-Okohi-Integration-Key' => $key])
                ->delete($baseUrl.'/api/v1/partner-integrations/revoke');
        }

        return back()->with('success', 'Intégration Okohi désactivée.');
    }

    private static function extractIntegrationKey(string $integrationUrl): ?string
    {
        // URL form: .../scan/{company_id}/{type}/{integration_key}/{ticket_id}/...
        $path = parse_url($integrationUrl, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path)));

        // Find the segment after "scan" + company_id + type = index 3
        // e.g. api/v1/scan/company-uuid/okohi/THE_KEY/...
        $scanIndex = array_search('scan', $segments);

        if ($scanIndex === false) {
            return null;
        }

        // key is 3 positions after "scan": scan / company_id / type / key
        return $segments[$scanIndex + 3] ?? null;
    }
}
