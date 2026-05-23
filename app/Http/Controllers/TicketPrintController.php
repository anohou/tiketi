<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TicketPrintController extends Controller
{
    public function print($ticketId)
    {
        $settings = TicketSetting::getSettings();
        $ticket = Ticket::with([
            'trip.route',
            'trip.vehicle',
            'fromStation',
            'toStation',
            'seller',
        ])->findOrFail($ticketId);

        $qrCode = ($settings->print_qr_code || $settings->hasOkohiIntegration())
            ? QrCode::size(96)->margin(0)->generate($ticket->printableQrValue($settings))
            : null;

        // Retourner la vue directement pour impression HTML
        return view('tickets.print', [
            'ticket' => $ticket,
            'qrCode' => $qrCode,
            'settings' => $settings,
        ]);
    }

    public function printMultiple(Request $request)
    {
        $settings = TicketSetting::getSettings();
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
        if ($settings->print_qr_code || $settings->hasOkohiIntegration()) {
            foreach ($tickets as $ticket) {
                $qrCodes[$ticket->id] = QrCode::size(96)->margin(0)->generate($ticket->printableQrValue($settings));
            }
        }

        $pdf = PDF::loadView('tickets.print-multiple', [
            'tickets' => $tickets,
            'qrCodes' => $qrCodes,
            'settings' => $settings,
        ]);

        return $pdf->stream('tickets-'.now()->format('Y-m-d-H-i-s').'.pdf');
    }

    /**
     * Export list of tickets as PDF
     * GET /tickets/export-pdf
     */
    public function exportPdf(Request $request)
    {
        $user = auth()->user();

        $ticketsQuery = Ticket::query()
            ->with(['trip.route', 'trip.vehicle.vehicleType', 'seller', 'fromStation', 'toStation'])
            ->orderBy('created_at', 'desc');

        // Date filters
        if ($request->filled('start_date')) {
            $ticketsQuery->whereDate('created_at', '>=', $request->get('start_date'));
        } else {
            $ticketsQuery->whereDate('created_at', today());
        }
        if ($request->filled('end_date')) {
            $ticketsQuery->whereDate('created_at', '<=', $request->get('end_date'));
        }

        // Trip filter
        if ($request->filled('trip_id')) {
            $ticketsQuery->where('trip_id', $request->get('trip_id'));
        }

        // Seller restriction
        if ($user->role === 'seller') {
            $ticketsQuery->where('seller_id', $user->id);
        }

        $tickets = $ticketsQuery->get();

        $totalAmount = $tickets->sum('price');
        $startDate = $request->get('start_date', today()->toDateString());
        $endDate = $request->get('end_date', today()->toDateString());

        $pdf = PDF::loadView('tickets.export-pdf', [
            'tickets' => $tickets,
            'totalAmount' => $totalAmount,
            'startDate' => Carbon::parse($startDate)->format('d/m/Y'),
            'endDate' => Carbon::parse($endDate)->format('d/m/Y'),
            'generatedAt' => now()->format('d/m/Y H:i'),
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download('rapport_tickets_'.now()->format('Y-m-d').'.pdf');
    }
}
