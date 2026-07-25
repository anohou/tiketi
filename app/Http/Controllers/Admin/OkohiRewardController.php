<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OkohiRewardController extends Controller
{
    public function customer(Request $request, string $customerNumber)
    {
        $settings = TicketSetting::getSettings();

        if (! $settings->hasOkohiIntegration()) {
            return response()->json(['error' => 'Integration not configured'], 400);
        }

        $integrationUrl = rtrim($settings->okohi_integration_url ?: config('services.okohi.base_url', 'http://127.0.0.1:8001'), '/');
        if (! str_contains($integrationUrl, '/api/v1/partner')) {
            $integrationUrl .= '/api/v1/partner';
        }

        $response = Http::timeout(15)
            ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
            ->get("{$integrationUrl}/customers/{$customerNumber}");

        $body = $response->json();

        if ($response->status() === 404) {
            return response()->json(['error' => 'Client introuvable. Vérifiez le numéro Okohi.'], 404);
        }

        if ($response->status() === 401) {
            return response()->json(['error' => 'Clé d\'intégration invalide.'], 401);
        }

        if (! $response->successful()) {
            $msg = $body['message'] ?? 'Erreur lors de la recherche du client.';

            return response()->json(['error' => $msg], $response->status());
        }

        $data = $body['data'] ?? $body;
        $recentTrips = collect($data['recent_trips'] ?? []);
        $tickets = Ticket::query()
            ->with(['fromStation:id,name', 'toStation:id,name', 'trip:id,departure_at'])
            ->whereIn('ticket_number', $recentTrips->pluck('ticket_id')->filter())
            ->get()
            ->keyBy('ticket_number');

        $data['recent_trips'] = $recentTrips->map(function (array $trip) use ($tickets) {
            $ticket = $tickets->get($trip['ticket_id'] ?? null);

            if (! $ticket) {
                return $trip;
            }

            return array_merge($trip, [
                'route_label' => collect([$ticket->fromStation?->name, $ticket->toStation?->name])
                    ->filter()
                    ->implode(' → '),
                'travelled_at' => $trip['travelled_at'] ?? $ticket->trip?->departure_at?->toIso8601String(),
            ]);
        })->values()->all();

        return response()->json($data);
    }

    public function grant(Request $request, string $customerNumber)
    {
        $request->validate(['reward_id' => 'required|string']);

        $settings = TicketSetting::getSettings();

        if (! $settings->hasOkohiIntegration()) {
            return response()->json(['error' => 'Integration not configured'], 400);
        }

        $integrationUrl = rtrim($settings->okohi_integration_url ?: config('services.okohi.base_url', 'http://127.0.0.1:8001'), '/');
        if (! str_contains($integrationUrl, '/api/v1/partner')) {
            $integrationUrl .= '/api/v1/partner';
        }

        $response = Http::timeout(15)
            ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
            ->post("{$integrationUrl}/customers/{$customerNumber}/grant-reward", [
                'reward_id' => $request->reward_id,
            ]);

        $body = $response->json();

        if (! $response->successful()) {
            $msg = $body['message'] ?? 'Erreur lors de l\'attribution de la récompense.';

            return response()->json(['error' => $msg], $response->status());
        }

        // Surface claim_id at the top level for easy frontend polling
        $claimId = $body['data']['claim']['id'] ?? null;

        return response()->json(array_merge($body, ['claim_id' => $claimId]));
    }
}
