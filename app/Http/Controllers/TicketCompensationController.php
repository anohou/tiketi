<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Services\TicketCompensationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TicketCompensationController extends Controller
{
    public function index()
    {
        return Inertia::render('Supervisor/Compensations', [
            'compensations' => TicketCompensation::with(['ticket', 'requestedBy', 'replacementTrip'])
                ->where('status', 'pending_approval')->oldest()->get(),
        ]);
    }

    public function store(Request $request, Ticket $ticket, TicketCompensationService $service)
    {
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
        return response()->json(['message' => 'Compensation approuvée.', 'compensation' => $service->approve($compensation, $request->user())]);
    }
}
