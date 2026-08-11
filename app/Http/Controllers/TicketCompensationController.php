<?php

namespace App\Http\Controllers;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Services\TicketCompensationService;
use App\Services\TicketRefundService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TicketCompensationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', TicketCompensation::class);

        return Inertia::render('Supervisor/Compensations', [
            'compensations' => TicketCompensation::with(['ticket', 'requestedBy', 'replacementTrip'])
                ->where('status', 'pending_approval')->oldest()->get(),
        ]);
    }

    public function store(Request $request, Ticket $ticket, TicketCompensationService $service)
    {
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'incident_type' => ['required', Rule::in(['trip_cancelled', 'missed_connection', 'delay', 'service_incident', 'commercial'])],
            'compensation_type' => ['required', Rule::in(['refund', 'credit', 'free_rebooking', 'fare_adjustment', 'exceptional_care'])],
            'amount' => ['nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'replacement_trip_id' => ['required_if:compensation_type,free_rebooking', 'nullable', 'uuid', 'exists:trips,id'],
            'replacement_seat_number' => ['required_if:compensation_type,free_rebooking', 'nullable', 'integer', 'min:1'],
        ]);
        $compensation = $service->request($ticket, $data, $request->user());

        return response()->json([
            'message' => $compensation->status === 'executed' ? 'Compensation exécutée.' : 'Compensation transmise au superviseur.',
            'compensation' => $compensation,
        ], 201);
    }

    public function approve(Request $request, TicketCompensation $compensation, TicketCompensationService $service)
    {
        $this->authorize('approve', $compensation);

        return response()->json(['message' => 'Compensation approuvée.', 'compensation' => $service->approve($compensation, $request->user())]);
    }

    /**
     * Remboursement partiel du retour d'un aller-retour (§11).
     * Écriture compensatoire : les prix historiques ne sont jamais écrasés.
     */
    public function refundReturn(Request $request, Ticket $ticket, TicketRefundService $service)
    {
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $service->refundReturn(
                $ticket,
                $request->user(),
                $data['reason'],
                isset($data['amount']) ? (int) $data['amount'] : null,
            );
        } catch (TicketingRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus);
        }

        return response()->json([
            'message' => "Retour remboursé ({$result['refunded_amount']} FCFA).",
            'refunded_amount' => $result['refunded_amount'],
            'compensation' => $result['compensation'],
            'journey' => $result['journey'],
        ], 201);
    }
}
