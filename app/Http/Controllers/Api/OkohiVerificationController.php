<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OkohiVerificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ticketId = (string) $request->query('ticket_id', '');
        $key = $request->header('X-Okohi-Integration-Key');

        if ($ticketId === '') {
            return response()->json(['valid' => false, 'message' => 'Missing ticket_id']);
        }

        // Tenancy déjà initialisée (appel via sous-domaine tenant)
        if (tenancy()->initialized) {
            return $this->respond($request, $ticketId);
        }

        // Pas de tenants (tests) — connexion courante
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return $this->respond($request, $ticketId);
        }

        // Chemin optimisé : tenant encodé dans l'URL lors de la connexion Okohi
        // verify_url envoyée : /api/okohi/verify?tenant={id}&ticket_id={ticket_id}
        $tenantId = (string) $request->query('tenant', '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant) {
                tenancy()->initialize($tenant);
                $result = $this->respond($request, $ticketId);
                tenancy()->end();

                return $result;
            }
        }

        // Fallback : parcourir tous les tenants (anciens liens sans ?tenant=)
        $found = null;

        $tenants->each(function (Tenant $tenant) use ($ticketId, $key, &$found) {
            if ($found !== null) {
                return false;
            }

            tenancy()->initialize($tenant);

            $settings = TicketSetting::getSettings();
            if (! $this->isAuthorized($request, $settings)) {
                tenancy()->end();

                return;
            }

            $ticket = Ticket::where('ticket_number', $ticketId)->first();

            if ($ticket?->status === 'issued') {
                $found = [
                    'ticket_id' => $ticket->ticket_number,
                    'amount' => (int) ($ticket->amount_collected ?? $ticket->price),
                    'timestamp' => $ticket->created_at?->timestamp ?? 0,
                ];
            }

            tenancy()->end();
        });

        if (! $found) {
            return response()->json(['valid' => false, 'message' => 'Ticket not found, cancelled or unauthorized']);
        }

        return response()->json(['valid' => true, 'data' => $found]);
    }

    private function respond(Request $request, string $ticketId): JsonResponse
    {
        $settings = TicketSetting::getSettings();

        if (! $this->isAuthorized($request, $settings)) {
            return response()->json(['valid' => false, 'message' => 'Unauthorized'], 401);
        }

        $ticket = Ticket::where('ticket_number', $ticketId)->first();

        if ($ticket?->status !== 'issued') {
            return response()->json(['valid' => false, 'message' => 'Ticket not found, cancelled or refunded']);
        }

        return response()->json([
            'valid' => true,
            'data' => [
                'ticket_id' => $ticket->ticket_number,
                'amount' => (int) ($ticket->amount_collected ?? $ticket->price),
                'timestamp' => $ticket->created_at?->timestamp ?? 0,
            ],
        ]);
    }

    private function isAuthorized(Request $request, TicketSetting $settings): bool
    {
        $secret = (string) $settings->okohi_integration_key;
        if ($secret === '') {
            return true;
        }

        $signature = (string) ($request->headers->get('x-okohi-signature') ?? '');
        $key = (string) ($request->headers->get('x-okohi-integration-key') ?? '');

        if ($signature !== '') {
            $rawBody = (string) $request->getContent();
            $fullUrl = $request->fullUrl();
            $method = $request->getMethod();
            $tsHeader = (string) ($request->headers->get('x-okohi-timestamp') ?? '');
            $nonceHeader = (string) ($request->headers->get('x-okohi-nonce') ?? '');
            $canonicalPayload = implode('|', array_filter([$method, $fullUrl, $tsHeader, $nonceHeader, $rawBody]));

            $sig1 = hash_hmac('sha256', $rawBody, $secret);
            $sig2 = hash_hmac('sha256', $canonicalPayload, $secret);

            return hash_equals($sig1, $signature) || hash_equals($sig2, $signature);
        }

        return $key !== '' && hash_equals($secret, $key);
    }
}
