<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OkohiVerificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ticketId = (string) $request->query('ticket_id', '');

        if ($ticketId === '') {
            return response()->json(['valid' => false, 'message' => 'Missing ticket_id']);
        }

        // Tenancy déjà initialisée (appel via sous-domaine tenant)
        if (tenancy()->initialized) {
            return $this->respond($ticketId);
        }

        // Pas de tenants (tests) — connexion courante
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return $this->respond($ticketId);
        }

        // Chemin optimisé : tenant encodé dans l'URL lors de la connexion Okohi
        // verify_url envoyée : /api/okohi/verify?tenant={id}&ticket_id={ticket_id}
        $tenantId = (string) $request->query('tenant', '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant) {
                tenancy()->initialize($tenant);
                $result = $this->respond($ticketId);
                tenancy()->end();

                return $result;
            }
        }

        // Fallback : parcourir tous les tenants (anciens liens sans ?tenant=)
        $found = null;

        $tenants->each(function (Tenant $tenant) use ($ticketId, &$found) {
            if ($found !== null) {
                return false;
            }

            tenancy()->initialize($tenant);

            $ticket = Ticket::where('ticket_number', $ticketId)->first();

            if ($ticket && $ticket->status !== 'cancelled') {
                $found = [
                    'ticket_id' => $ticket->ticket_number,
                    'amount' => (int) $ticket->price,
                    'timestamp' => $ticket->created_at?->timestamp ?? 0,
                ];
            }

            tenancy()->end();
        });

        if (! $found) {
            return response()->json(['valid' => false, 'message' => 'Ticket not found or cancelled']);
        }

        return response()->json(['valid' => true, 'data' => $found]);
    }

    private function respond(string $ticketId): JsonResponse
    {
        $ticket = Ticket::where('ticket_number', $ticketId)->first();

        if (! $ticket || $ticket->status === 'cancelled') {
            return response()->json(['valid' => false, 'message' => 'Ticket not found or cancelled']);
        }

        return response()->json([
            'valid' => true,
            'data' => [
                'ticket_id' => $ticket->ticket_number,
                'amount' => (int) $ticket->price,
                'timestamp' => $ticket->created_at?->timestamp ?? 0,
            ],
        ]);
    }
}
