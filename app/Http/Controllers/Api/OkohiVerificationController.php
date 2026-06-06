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
            return response()->json(['success' => false], 422);
        }

        // Tenancy déjà initialisée (appel depuis test.localhost)
        if (tenancy()->initialized) {
            return $this->respond($ticketId);
        }

        // Appel via IP ou domaine central : cherche dans tous les tenants
        $found = null;

        Tenant::all()->each(function (Tenant $tenant) use ($ticketId, &$found) {
            if ($found !== null) {
                return false;
            }

            tenancy()->initialize($tenant);

            $ticket = Ticket::where('ticket_number', $ticketId)->first();

            if ($ticket && $ticket->status !== 'cancelled') {
                // Extraire les données AVANT tenancy()->end()
                $found = [
                    'ticket_id' => $ticket->ticket_number,
                    'amount' => (int) $ticket->price,
                    'created_at' => $ticket->created_at?->toIso8601String(),
                ];
            }

            tenancy()->end();
        });

        if (! $found) {
            return response()->json(['success' => false], 404);
        }

        return response()->json(['success' => true, 'data' => $found]);
    }

    private function respond(string $ticketId): JsonResponse
    {
        $ticket = Ticket::where('ticket_number', $ticketId)->first();

        if (! $ticket || $ticket->status === 'cancelled') {
            return response()->json(['success' => false], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'ticket_id' => $ticket->ticket_number,
                'amount' => (int) $ticket->price,
                'created_at' => $ticket->created_at?->toIso8601String(),
            ],
        ]);
    }
}
