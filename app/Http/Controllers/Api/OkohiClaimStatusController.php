<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Illuminate\Support\Facades\Http;

class OkohiClaimStatusController extends Controller
{
    public function __invoke(string $claimId)
    {
        $settings = TicketSetting::getSettings();

        if (! $settings->hasOkohiIntegration()) {
            return response()->json(['error' => 'Integration not configured'], 400);
        }

        $baseUrl = rtrim(config('services.okohi.base_url'), '/');

        $response = Http::timeout(10)
            ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
            ->get("{$baseUrl}/api/v1/partner/claims/{$claimId}");

        if (! $response->successful()) {
            return response()->json(['error' => 'Impossible de récupérer le statut du claim'], $response->status());
        }

        return response()->json($response->json());
    }
}
