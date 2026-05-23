<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $ticket = Ticket::query()
            ->where('ticket_number', $ticketId)
            ->orWhere('id', $ticketId)
            ->first();

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
