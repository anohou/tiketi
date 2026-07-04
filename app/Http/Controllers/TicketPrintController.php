<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Services\TicketQueryService;
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

        $pdf = Pdf::loadView('tickets.print-multiple', [
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
        $tripId = $request->get('trip_id');
        $hasExplicitDateRange = $request->filled('start_date') || $request->filled('end_date');

        $tickets = app(TicketQueryService::class)
            ->getFilteredTicketsQuery($request, $user)
            ->get();
        $trip = $tripId
            ? Trip::with(['route.originStation', 'route.destinationStation', 'route.routeStopOrders.station', 'vehicle'])->find($tripId)
            : null;

        $totalAmount = $tickets->sum('price');
        $routeStationOrderMap = [];
        if ($trip?->route?->relationLoaded('routeStopOrders')) {
            foreach ($trip->route->routeStopOrders as $order) {
                $stationId = $order->station_id ?? null;
                if ($stationId && ! array_key_exists($stationId, $routeStationOrderMap)) {
                    $routeStationOrderMap[$stationId] = (int) ($order->stop_index ?? 999);
                }
            }
        }

        $groupedTickets = $tickets
            ->groupBy(fn (Ticket $ticket) => $ticket->from_station_id ?? 'unknown')
            ->map(function ($group) {
                $sortedGroup = $group->sortByDesc('created_at')->values();
                $stationName = $sortedGroup->first()?->fromStation?->name ?? 'Gare inconnue';

                return [
                    'station_name' => $stationName,
                    'tickets' => $sortedGroup,
                    'count' => $sortedGroup->count(),
                    'amount' => $sortedGroup->sum('price'),
                ];
            })
            ->sortBy(function (array $group) use ($routeStationOrderMap) {
                $firstTicket = $group['tickets']->first();
                $stationId = $firstTicket?->from_station_id ?? null;

                if (! empty($routeStationOrderMap)) {
                    if ($stationId && array_key_exists($stationId, $routeStationOrderMap)) {
                        return $routeStationOrderMap[$stationId];
                    }

                    return 9999;
                }

                return mb_strtolower($group['station_name'] ?? '');
            })
            ->values();

        $periodLabel = null;
        $startDate = null;
        $endDate = null;

        if ($hasExplicitDateRange) {
            $startDate = $request->get('start_date', $request->get('end_date'));
            $endDate = $request->get('end_date', $request->get('start_date'));
        } elseif ($trip) {
            $periodLabel = 'Toutes les ventes du voyage';
        } else {
            $startDate = today()->toDateString();
            $endDate = today()->toDateString();
        }

        $pdf = Pdf::loadView('tickets.export-pdf', [
            'tickets' => $tickets,
            'totalAmount' => $totalAmount,
            'periodLabel' => $periodLabel,
            'startDate' => $startDate ? Carbon::parse($startDate)->format('d/m/Y') : null,
            'endDate' => $endDate ? Carbon::parse($endDate)->format('d/m/Y') : null,
            'generatedAt' => now()->format('d/m/Y H:i'),
            'trip' => $trip,
            'groupedTickets' => $groupedTickets,
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download('rapport_tickets_'.now()->format('Y-m-d').'.pdf');
    }
}
