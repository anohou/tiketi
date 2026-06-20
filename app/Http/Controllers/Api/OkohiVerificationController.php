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
            return response()->json(['valid' => false, 'reason' => 'Missing ticket_id'], 422);
        }

        if (tenancy()->initialized) {
            return $this->respond($ticketId, $request);
        }

        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            return $this->respond($ticketId, $request);
        }

        $found = null;

        $tenants->each(function (Tenant $tenant) use ($ticketId, &$found) {
            if ($found !== null) {
                return false;
            }

            tenancy()->initialize($tenant);

            $ticket = Ticket::where('ticket_number', $ticketId)->first();

            if ($ticket && $ticket->status !== 'cancelled') {
                $found = [
                    'valid' => true,
                    'ticket_id' => $ticket->ticket_number,
                    'amount' => (int) $ticket->price,
                    'timestamp' => $ticket->created_at?->timestamp ?? 0,
                ];
            }

            tenancy()->end();
        });

        if (! $found) {
            return response()->json(['valid' => false, 'reason' => 'Ticket not found or cancelled'], 404);
        }

        return response()->json($found);
    }

    private function respond(string $ticketId, Request $request): JsonResponse
    {
        $ticket = Ticket::where('ticket_number', $ticketId)->first();

        if (! $ticket || $ticket->status === 'cancelled') {
            return response()->json(['valid' => false, 'reason' => 'Ticket not found or cancelled'], 404);
        }

        return response()->json([
            'valid' => true,
            'ticket_id' => $ticket->ticket_number,
            'amount' => (int) $ticket->price,
            'timestamp' => $ticket->created_at?->timestamp ?? 0,
        ]);
    }
}
