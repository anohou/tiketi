<?php

namespace App\Http\Controllers\Api;

use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Services\TripSegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::with(['trip', 'seller'])
            ->where('seller_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($tickets);
    }

    public function store(Request $request, TripSegmentService $segments)
    {
        $validated = $request->validate([
            'trip_id' => 'required|uuid|exists:trips,id',
            'from_station_id' => 'required|uuid|exists:stations,id',
            'to_station_id' => 'required|uuid|exists:stations,id',
            'seats' => 'required|array|min:1',
            'seats.*' => 'integer|min:1',
            'passenger_name' => 'nullable|string|max:255',
            'passenger_phone' => 'nullable|string|max:20',
            'amount' => 'nullable|integer|min:0',
        ]);

        $trip = Trip::with(['route.routeStopOrders', 'tripSeatOccupancies.ticket', 'vehicle.vehicleType'])->findOrFail($validated['trip_id']);

        $fromStationId = $validated['from_station_id'];
        $toStationId = $validated['to_station_id'];
        [$validSegment, $segmentError, $stationIndices, $reqStartIndex, $reqEndIndex] = $segments->validateSegment($trip, $fromStationId, $toStationId);

        if (! $validSegment) {
            return $this->errorResponse($request, $segmentError, 422);
        }

        $pricePerSeat = $segments->fareAmount($fromStationId, $toStationId);
        if ($pricePerSeat === null) {
            return $this->errorResponse($request, 'Aucun tarif actif trouvé pour ce trajet.', 422);
        }

        $expectedAmount = $pricePerSeat * count($validated['seats']);
        if (isset($validated['amount']) && (int) $validated['amount'] !== $expectedAmount) {
            return $this->errorResponse($request, 'Montant invalide pour ce trajet. Veuillez rafraîchir les tarifs.', 422);
        }

        // Restriction station vendeur
        $user = auth()->user();
        if ($user->role === 'seller') {
            $assignedStationIds = $user->stationAssignments()->where('active', true)->pluck('station_id')->toArray();

            if (! in_array($fromStationId, $assignedStationIds)) {
                return $this->errorResponse($request, 'Vous n\'êtes pas autorisé à vendre des tickets au départ de cette station.', 403);
            }

            $isAtOriginStation = in_array($trip->origin_station_id, $assignedStationIds);

            if (! $isAtOriginStation && $trip->isSalesClosed()) {
                $seatsFreedAtThisStation = $trip->tripSeatOccupancies
                    ->filter(fn ($occ) => $occ->ticket && $occ->ticket->to_station_id === $fromStationId)
                    ->pluck('seat_number')
                    ->toArray();

                $seatsNotFreed = array_diff($validated['seats'], $seatsFreedAtThisStation);

                if (! empty($seatsNotFreed)) {
                    return $this->errorResponse($request, 'Ce voyage est fermé aux ventes intermédiaires. Vous ne pouvez vendre que les places libérées à votre gare.', 403);
                }
            }
        }

        // Segment overlap check
        $occupiedSeats = $segments->overlappingSeatNumbers($trip->tripSeatOccupancies, $stationIndices, $reqStartIndex, $reqEndIndex);

        $conflictingSeats = array_intersect($validated['seats'], $occupiedSeats);
        if (! empty($conflictingSeats)) {
            return $this->errorResponse($request, 'Certaines places sont déjà occupées pour ce segment: '.implode(', ', $conflictingSeats), 422);
        }

        if (max($validated['seats']) > $trip->vehicle->seat_count) {
            return $this->errorResponse($request, 'Certaines places n\'existent pas.', 422);
        }

        // Redis lock to prevent double bookings
        $lock = Cache::lock('booking_trip_'.$trip->id, 5);

        if (! $lock->block(3)) {
            return $this->errorResponse($request, 'Le système est actuellement très sollicité pour ce voyage. Veuillez réessayer dans un instant.', 409);
        }

        try {
            DB::beginTransaction();

            // Double check only conflicting segment overlaps, so non-overlapping segments can reuse seats.
            $candidateOccupancies = TripSeatOccupancy::with('ticket')
                ->where('trip_id', $trip->id)
                ->whereIn('seat_number', $validated['seats'])
                ->get();
            $lockedOccupancies = $segments->overlappingSeatNumbers($candidateOccupancies, $stationIndices, $reqStartIndex, $reqEndIndex);

            if (! empty($lockedOccupancies)) {
                DB::rollBack();

                return $this->errorResponse($request, 'Ces places viennent d\'être réservées par un autre agent: '.implode(', ', $lockedOccupancies), 409);
            }

            $sellerStationId = $user->role === 'seller'
                ? $user->stationAssignments()->where('active', true)->first()?->station_id
                : $fromStationId;

            $optService = app(\App\Services\OptimisationService::class);
            $vehicleType = $trip->vehicle->vehicleType;

            $tickets = [];
            foreach ($validated['seats'] as $seatNumber) {
                $boardingGroup = $optService->computeBoardingGroup($vehicleType, $seatNumber);

                $ticket = Ticket::create([
                    'ticket_number' => 'TKT-'.strtoupper(Str::random(8)),
                    'trip_id' => $trip->id,
                    'vehicle_id' => $trip->vehicle_id,
                    'from_station_id' => $fromStationId,
                    'to_station_id' => $toStationId,
                    'seat_number' => $seatNumber,
                    'passenger_name' => $validated['passenger_name'] ?? 'Passager',
                    'passenger_phone' => $validated['passenger_phone'] ?? '',
                    'price' => $pricePerSeat,
                    'seller_id' => auth()->id(),
                    'station_id' => $sellerStationId,
                    'qr_code' => 'QR-'.strtoupper(Str::random(12)),
                    'boarding_group' => $boardingGroup,
                ]);
                $ticket->load(['fromStation', 'toStation']);
                $ticket->update(['qr_payload' => $ticket->qrPayloadData()]);

                TripSeatOccupancy::create([
                    'trip_id' => $trip->id,
                    'seat_number' => $seatNumber,
                    'ticket_id' => $ticket->id,
                ]);

                $tickets[] = $ticket;
            }

            DB::commit();

            // Broadcast seat map update
            try {
                $changedSeats = array_map(fn ($t) => [
                    'seat_number' => $t->seat_number,
                    'status' => 'occupied',
                    'ticket_id' => $t->id,
                    'to_station_id' => $t->to_station_id,
                ], $tickets);
                event(new SeatMapUpdated($trip->id, $changedSeats));
            } catch (\Exception $e) {
                Log::warning('Échec broadcast SeatMapUpdated: '.$e->getMessage());
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Tickets créés avec succès',
                    'tickets' => $tickets,
                    'total_amount' => $expectedAmount,
                    'print_url' => route('tickets.print-multiple'),
                    'ticket_ids' => collect($tickets)->pluck('id')->toArray(),
                ], 201);
            }

            return redirect()->back()->with([
                'flash' => [
                    'ticket_id' => $tickets[0]->id,
                    'ticket_ids' => collect($tickets)->pluck('id')->toArray(),
                    'message' => 'Ticket créé avec succès',
                ],
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            if (isset($lock)) {
                $lock->release();
            }
            DB::rollBack();

            if ($e->getCode() === '23000') {
                return $this->errorResponse($request, 'Une ou plusieurs places viennent d\'être réservées par un autre agent. Veuillez rafraîchir le plan.', 409);
            }

            Log::error('Erreur DB création ticket: '.$e->getMessage());

            return $this->errorResponse($request, 'Erreur lors de la création des tickets.', 500);

        } catch (\Exception $e) {
            if (isset($lock)) {
                $lock->release();
            }
            DB::rollBack();
            Log::error('Erreur création ticket: '.$e->getMessage());

            return $this->errorResponse($request, 'Erreur lors de la création des tickets.', 500);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }
    }

    public function show(Ticket $ticket)
    {
        $ticket->load(['trip.route', 'trip.vehicle', 'fromStation', 'toStation', 'seller']);

        try {
            $settings = \App\Models\TicketSetting::getSettings();
        } catch (\Exception $e) {
            Log::warning('Failed to get ticket settings: '.$e->getMessage());
            $settings = [
                'company_name' => 'TSR CI',
                'phone_numbers' => ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
                'cc_label' => null,
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'baggage_policy_message' => "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
                'print_qr_code' => false,
                'qr_code_base_url' => null,
                'okohi_enabled' => false,
                'okohi_host' => null,
                'okohi_company_id' => null,
                'okohi_loyalty_type' => 'points',
                'okohi_integration_key' => null,
            ];
        }

        $ticketArray = $ticket->toArray();
        $ticketArray['settings'] = $settings;
        $ticketArray['qr_payload_string'] = $ticket->printableQrValue($settings instanceof \App\Models\TicketSetting ? $settings : null);
        $ticketArray['tiketi_qr_payload_string'] = $ticket->qrPayloadString();

        return response()->json($ticketArray);
    }

    public function cancel(Request $request, Ticket $ticket)
    {
        if (! $this->canCancel($ticket)) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $tripId = $ticket->trip_id;
        $seatNumber = $ticket->seat_number;

        try {
            DB::beginTransaction();
            TripSeatOccupancy::where('ticket_id', $ticket->id)->delete();
            $ticket->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => $request->input('reason'),
            ]);
            DB::commit();

            try {
                event(new SeatMapUpdated($tripId, [
                    ['seat_number' => $seatNumber, 'status' => 'available'],
                ]));
            } catch (\Exception $e) {
                Log::warning('Échec broadcast SeatMapUpdated: '.$e->getMessage());
            }

            return response()->json(['message' => 'Ticket annulé avec succès']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Erreur lors de l\'annulation'], 500);
        }
    }

    public function destroy(Ticket $ticket)
    {
        return $this->cancel(request(), $ticket);
    }

    /**
     * Export tickets as JSON for client-side Excel generation
     * GET /api/tickets/export
     */
    public function export(Request $request)
    {
        $user = auth()->user();

        $ticketsQuery = Ticket::query()
            ->with(['trip.route', 'trip.vehicle.vehicleType', 'seller', 'fromStation', 'toStation'])
            ->orderBy('created_at', 'desc');

        // Filter by date range if provided
        if ($request->filled('start_date')) {
            $ticketsQuery->whereDate('created_at', '>=', $request->get('start_date'));
        } else {
            $ticketsQuery->whereDate('created_at', today());
        }
        if ($request->filled('end_date')) {
            $ticketsQuery->whereDate('created_at', '<=', $request->get('end_date'));
        }

        // Filter by trip_id if provided
        if ($request->filled('trip_id')) {
            $ticketsQuery->where('trip_id', $request->get('trip_id'));
        }

        // Sellers can only export their own tickets
        if ($user->role === 'seller') {
            $ticketsQuery->where('seller_id', $user->id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $ticketsQuery->where('status', $request->get('status'));
        } else {
            $ticketsQuery->where('status', '!=', 'cancelled');
        }

        $tickets = $ticketsQuery->get();

        $exportData = $tickets->map(function ($ticket) {
            return [
                'n_ticket' => $ticket->ticket_number,
                'date' => $ticket->created_at->format('d/m/Y'),
                'heure' => $ticket->created_at->format('H:i'),
                'ligne' => $ticket->trip?->route?->name ?? '-',
                'depart' => $ticket->fromStation?->name ?? '-',
                'arrivee' => $ticket->toStation?->name ?? '-',
                'place' => $ticket->seat_number ?? '-',
                'zone_embarquement' => $ticket->boarding_group ?? '-',
                'prix_fcfa' => $ticket->price,
                'vendeur' => $ticket->seller?->name ?? '-',
                'passager' => $ticket->passenger_name ?? 'Anonyme',
                'telephone' => $ticket->passenger_phone ?? '-',
                'statut' => $ticket->status === 'cancelled' ? 'Annulé' : 'Valide',
                'date_voyage' => $ticket->trip?->departure_at?->format('d/m/Y H:i') ?? '-',
                'vehicule' => $ticket->trip?->vehicle?->identifier ?? '-',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $tickets->values(),
            'total' => $tickets->count(),
            'message' => $tickets->count().' tickets exportés avec succès',
        ]);
    }

    private function canCancel(Ticket $ticket): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (in_array($user->role, ['admin', 'supervisor'], true)) {
            return true;
        }

        return $ticket->seller_id === $user->id;
    }

    private function errorResponse(Request $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withErrors(['general' => $message]);
    }
}
