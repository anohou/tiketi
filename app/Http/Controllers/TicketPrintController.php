<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TicketPrintController extends Controller
{
    public function print($ticketId)
    {
        $ticket = Ticket::with([
            'trip.route',
            'trip.vehicle',
            'fromStation',
            'toStation',
            'seller',
        ])->findOrFail($ticketId);

        // Générer le QR code
        $qrCode = QrCode::size(100)->generate(json_encode([
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'trip_id' => $ticket->trip_id,
            'from_stop' => $ticket->fromStation->name,
            'to_stop' => $ticket->toStation->name,
            'seat_number' => $ticket->seat_number,
            'passenger_name' => $ticket->passenger_name,
            'amount' => $ticket->amount,
            'timestamp' => $ticket->created_at->timestamp,
        ]));

        // Retourner la vue directement pour impression HTML
        return view('tickets.print', [
            'ticket' => $ticket,
            'qrCode' => $qrCode,
        ]);
    }

    public function printMultiple(Request $request)
    {
        $ticketIds = $request->validate([
            'ticket_ids' => 'required|array',
            'ticket_ids.*' => 'uuid|exists:tickets,id',
        ])['ticket_ids'];

        $tickets = Ticket::with([
            'trip.route',
            'trip.vehicle',
            'fromStation',
            'toStation',
            'seller',
        ])->whereIn('id', $ticketIds)->get();

        $qrCodes = [];
        foreach ($tickets as $ticket) {
            $qrCodes[$ticket->id] = QrCode::size(100)->generate(json_encode([
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'trip_id' => $ticket->trip_id,
                'from_stop' => $ticket->fromStation->name,
                'to_stop' => $ticket->toStation->name,
                'seat_number' => $ticket->seat_number,
                'passenger_name' => $ticket->passenger_name,
                'amount' => $ticket->amount,
                'timestamp' => $ticket->created_at->timestamp,
            ]));
        }

        $pdf = PDF::loadView('tickets.print-multiple', [
            'tickets' => $tickets,
            'qrCodes' => $qrCodes,
        ]);

        return $pdf->stream('tickets-'.now()->format('Y-m-d-H-i-s').'.pdf');
    }
}
