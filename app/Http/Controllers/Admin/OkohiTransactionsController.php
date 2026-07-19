<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OkohiTransactionsController extends Controller
{
    public function index(Request $request)
    {
        $settings = TicketSetting::getSettings();

        if (! $settings->hasOkohiIntegration()) {
            return response()->json(['error' => 'Integration not configured'], 400);
        }

        $baseUrl = rtrim(config('services.okohi.base_url'), '/');
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 15);

        $response = Http::timeout(15)
            ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
            ->get($baseUrl.'/api/v1/partner/transactions', [
                'page' => $page,
                'per_page' => $perPage,
            ]);

        if ($response->status() === 401) {
            return response()->json(['error' => 'Clé d\'intégration invalide'], 401);
        }

        if ($response->status() === 403) {
            return response()->json(['error' => 'Intégration désactivée côté Okohi'], 403);
        }

        if (! $response->successful()) {
            return response()->json(['error' => 'Erreur lors de la récupération des transactions'], 500);
        }

        return response()->json($response->json());
    }
}
