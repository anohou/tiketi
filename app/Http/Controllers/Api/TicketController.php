<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ticketing\TripSalesPolicy;
use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\CancelOrReverseOkohiClaimJob;
use App\Models\Route;
use App\Models\Ticket;
use App\Models\TicketConnection;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use App\Services\OptimisationService;
use App\Services\TicketQueryService;
use App\Services\TripSegmentService;
use App\Services\TripStationProgression;
use App\Services\TripTimingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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
            'seats.*' => 'integer|min:1|distinct',
            'passenger_name' => 'nullable|string|max:255',
            'passenger_phone' => 'nullable|string|max:20',
            'amount' => 'nullable|integer|min:0',
            'final_destination_station_id' => 'nullable|uuid|exists:stations,id',
            'connection_route_id' => 'nullable|required_with:final_destination_station_id|uuid|exists:routes,id',
        ]);

        $trip = Trip::with(['route.routeStopOrders', 'tripSeatOccupancies.ticket', 'vehicle.vehicleType'])->findOrFail($validated['trip_id']);

        $fromStationId = $validated['from_station_id'];
        $toStationId = $validated['to_station_id'];
        $finalDestinationId = $validated['final_destination_station_id'] ?? null;

        $salesDecision = app(TripSalesPolicy::class)->evaluate(
            $request->user() ?? auth()->user(),
            $trip,
            $fromStationId,
            $toStationId,
            'counter',
            $validated['seats'],
        );
        if (! $salesDecision->allowed) {
            return $this->errorResponse(
                $request,
                $salesDecision->message,
                in_array($salesDecision->reasonCode, ['unauthenticated'], true) ? 401 : 403,
            );
        }

        if ($finalDestinationId && ! $trip->allows_open_connections) {
            return $this->errorResponse($request, 'Ce voyage n’autorise pas les correspondances ouvertes.', 422);
        }

        if ($finalDestinationId && $finalDestinationId === $fromStationId) {
            return $this->errorResponse(
                $request,
                'La destination finale d’une correspondance doit être différente de la gare d’origine.',
                422,
            );
        }

        if ($finalDestinationId && $finalDestinationId === $toStationId) {
            $finalDestinationId = null;
        }

        if ($finalDestinationId && $this->isServedByCurrentTripAfter($trip, $toStationId, $finalDestinationId, $segments)) {
            return $this->errorResponse(
                $request,
                'Cette destination est déjà desservie par le voyage en cours après la gare sélectionnée.',
                422,
            );
        }

        $connectionRouteId = $finalDestinationId ? ($validated['connection_route_id'] ?? null) : null;
        if ($connectionRouteId) {
            $connectionRoute = Route::with('routeStopOrders')->find($connectionRouteId);
            if (! $connectionRoute || ! $connectionRoute->active) {
                return $this->errorResponse($request, 'Le trajet de correspondance sélectionné est invalide ou inactif.', 422);
            }
            $stationIds = collect([$connectionRoute->origin_station_id])
                ->merge($connectionRoute->routeStopOrders->sortBy('stop_index')->pluck('station_id'))
                ->push($connectionRoute->destination_station_id)
                ->filter()
                ->unique()
                ->values();
            $transferIndex = $stationIds->search($toStationId);
            $destinationIndex = $stationIds->search($finalDestinationId);
            if ($transferIndex === false || $destinationIndex === false) {
                return $this->errorResponse($request, 'La destination finale n\'appartient pas au bassin de correspondance de cette route.', 422);
            }
        }
        [$validSegment, $segmentError, $stationIndices, $reqStartIndex, $reqEndIndex] = $segments->validateSegment($trip, $fromStationId, $toStationId);

        if (! $validSegment) {
            return $this->errorResponse($request, $segmentError, 422);
        }

        $pricePerSeat = null;
        $fareCalculation = [];

        if ($finalDestinationId) {
            // First search for global explicit fare
            $globalFare = $segments->fareAmount($fromStationId, $finalDestinationId);
            if ($globalFare !== null) {
                $pricePerSeat = $globalFare;
                $fareCalculation = [
                    'type' => 'global',
                    'amount' => $globalFare,
                ];
            } else {
                // Sum the two segments
                $first = $segments->fareAmount($fromStationId, $toStationId);
                $second = $segments->fareAmount($toStationId, $finalDestinationId);
                if ($first !== null && $second !== null) {
                    $pricePerSeat = $first + $second;
                    $fareCalculation = [
                        'type' => 'segments_sum',
                        'amount' => $pricePerSeat,
                        'segments' => [
                            ['from_station_id' => $fromStationId, 'to_station_id' => $toStationId, 'amount' => $first],
                            ['from_station_id' => $toStationId, 'to_station_id' => $finalDestinationId, 'amount' => $second],
                        ],
                    ];
                }
            }
        } else {
            $directFare = $segments->fareAmount($fromStationId, $toStationId);
            if ($directFare !== null) {
                $pricePerSeat = $directFare;
                $fareCalculation = [
                    'type' => 'direct',
                    'amount' => $directFare,
                ];
            }
        }

        if ($pricePerSeat === null) {
            return $this->errorResponse($request, 'Aucun tarif actif trouvé entre le point de départ et la destination finale.', 422);
        }

        $expectedAmount = $pricePerSeat * count($validated['seats']);
        if (isset($validated['amount']) && (int) $validated['amount'] !== $expectedAmount) {
            return $this->errorResponse($request, 'Montant invalide pour ce trajet. Veuillez rafraîchir les tarifs.', 422);
        }

        // Restriction station vendeur
        $user = auth()->user();
        if ($user->role === 'seller') {
            $assignedStationIds = $user->getActiveStationIds();

            if (! in_array($fromStationId, $assignedStationIds)) {
                return $this->errorResponse($request, 'Vous n\'êtes pas autorisé à vendre des tickets au départ de cette station.', 403);
            }

            // Restriction trajet vendeur
            $accessibleRouteIds = $user->accessibleRoutesQuery()->pluck('id')->toArray();
            if (! in_array($trip->route_id, $accessibleRouteIds, true)) {
                return $this->errorResponse($request, 'Vous n\'êtes pas autorisé à vendre des tickets pour ce trajet.', 403);
            }

            $isAtOriginStation = in_array($trip->origin_station_id, $assignedStationIds);

            if ($trip->status === 'departed') {
                $activeStationId = app(TripStationProgression::class)->activeSalesStationId($trip);
                if ($fromStationId !== $activeStationId) {
                    return $this->errorResponse($request, 'Cette gare n’a pas encore la main sur les ventes de ce voyage. Attendez le départ de la gare précédente.', 403);
                }
            }

            if (! $isAtOriginStation && $trip->isSalesClosed() && $trip->status !== 'departed') {
                $seatsFreedAtThisStation = $segments->freedSeatsForStation($trip, $fromStationId);

                if (count($validated['seats']) > count($seatsFreedAtThisStation)) {
                    return $this->errorResponse(
                        $request,
                        'La quantité demandée dépasse le nombre de places libérées et vendables à votre gare.',
                        422,
                    );
                }

                $seatsNotFreed = array_diff($validated['seats'], $seatsFreedAtThisStation);

                if (! empty($seatsNotFreed)) {
                    return $this->errorResponse($request, 'La vente simultanée est désactivée jusqu’au départ de ce voyage. Vous ne pouvez vendre que les places libérées à votre gare.', 403);
                }
            }
        }

        // Segment overlap check
        $occupiedSeats = $segments->overlappingSeatNumbers($trip->tripSeatOccupancies, $stationIndices, $reqStartIndex, $reqEndIndex);
        $availableSeatCount = max(0, $trip->total_seats - count($occupiedSeats));

        if (count($validated['seats']) > $availableSeatCount) {
            return $this->errorResponse(
                $request,
                'La quantité demandée dépasse le nombre de places vendables pour ce trajet.',
                422,
            );
        }

        $conflictingSeats = array_intersect($validated['seats'], $occupiedSeats);
        if (! empty($conflictingSeats)) {
            return $this->errorResponse($request, 'Certaines places sont déjà occupées pour ce segment: '.implode(', ', $conflictingSeats), 422);
        }

        if (max($validated['seats']) > $trip->vehicle->seat_count) {
            return $this->errorResponse($request, 'Certaines places n\'existent pas.', 422);
        }

        try {
            DB::beginTransaction();

            // Serialize bookings per trip at the database level so we don't
            // depend on cache-tag-compatible stores for concurrency control.
            Trip::query()
                ->whereKey($trip->id)
                ->lockForUpdate()
                ->firstOrFail();

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

            $optService = app(OptimisationService::class);
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
                    'final_destination_station_id' => $finalDestinationId,
                    'transfer_station_id' => $finalDestinationId ? $toStationId : null,
                    'seat_number' => $seatNumber,
                    'passenger_name' => $validated['passenger_name'] ?? 'Passager',
                    'passenger_phone' => $validated['passenger_phone'] ?? '',
                    'price' => $pricePerSeat,
                    'seller_id' => auth()->id(),
                    'station_id' => $sellerStationId,
                    'qr_code' => 'QR-'.strtoupper(Str::random(12)),
                    'boarding_group' => $boardingGroup,
                    'payment_method' => 'cash',
                    'gross_amount' => $pricePerSeat,
                    'discount_amount' => 0,
                    'amount_collected' => $pricePerSeat,
                    'settings' => [
                        'fare_calculation' => $fareCalculation,
                    ],
                ]);
                $ticket->load(['fromStation', 'toStation']);
                $ticket->update(['qr_payload' => $ticket->qrPayloadData()]);

                TripSeatOccupancy::create([
                    'trip_id' => $trip->id,
                    'seat_number' => $seatNumber,
                    'ticket_id' => $ticket->id,
                    'from_station_id' => $fromStationId,
                    'to_station_id' => $toStationId,
                ]);

                if ($finalDestinationId) {
                    TicketConnection::create([
                        'ticket_id' => $ticket->id,
                        'transfer_station_id' => $toStationId,
                        'destination_station_id' => $finalDestinationId,
                        'route_id' => $connectionRouteId,
                        'status' => 'pending',
                        'planned_ready_at' => app(TripTimingService::class)->plannedTimeAtStation($trip, $toStationId),
                        'estimated_ready_at' => app(TripTimingService::class)->estimatedTimeAtStation($trip, $toStationId),
                    ]);
                }

                $tickets[] = $ticket;
            }

            DB::commit();

            // Broadcast seat map update
            try {
                $broadcastTrip = Trip::with([
                    'route.routeStopOrders',
                    'originStation',
                    'destinationStation',
                    'vehicle.vehicleType',
                ])->findOrFail($trip->id);
                $changedSeats = array_map(fn ($t) => [
                    'seat_number' => $t->seat_number,
                    'status' => 'occupied',
                    'ticket_id' => $t->id,
                    'to_station_id' => $t->to_station_id,
                ], $tickets);
                event(new SeatMapUpdated(
                    $broadcastTrip,
                    $changedSeats,
                    'ticket.created',
                    $sellerStationId
                ));
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

        } catch (QueryException $e) {
            DB::rollBack();

            if ($e->getCode() === '23000') {
                return $this->errorResponse($request, 'Une ou plusieurs places viennent d\'être réservées par un autre agent. Veuillez rafraîchir le plan.', 409);
            }

            Log::error('Erreur DB création ticket: '.$e->getMessage());

            return $this->errorResponse($request, 'Erreur lors de la création des tickets.', 500);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur création ticket: '.$e->getMessage());

            return $this->errorResponse($request, 'Erreur lors de la création des tickets.', 500);
        }
    }

    public function show(Ticket $ticket)
    {
        $user = auth()->user();
        abort_unless(
            $user instanceof User
                && (in_array($user->role, ['admin', 'supervisor'], true) || $ticket->seller_id === $user->id),
            403,
            'Vous n’êtes pas autorisé à consulter les données de ce ticket.',
        );

        $ticket->load(['trip.route', 'trip.vehicle', 'fromStation', 'toStation', 'finalDestinationStation', 'transferStation', 'connection.trip', 'seller']);

        try {
            $settings = TicketSetting::getSettings();
        } catch (\Exception $e) {
            Log::warning('Failed to get ticket settings: '.$e->getMessage());
            $settings = [
                'company_name' => 'TEST TRANSPORT',
                'phone_numbers' => ['+225 XX XX XX XX XX', '+225 XX XX XX XX XX'],
                'cc_label' => null,
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'baggage_policy_message' => "La perte des bagages transportes doit faire l'objet d'une declaration aux agences de la societe.",
                'baggage_policy_message_2' => "Les objets de valeur doivent faire l'objet d'une declaration en sus de l'enregistrement avec pieces justificatives avant le depart.",
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
        $ticketArray['qr_payload_string'] = $ticket->printableQrValue($settings instanceof TicketSetting ? $settings : null);
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
            $ticket->connection()->update(['status' => 'cancelled']);

            $ticketSettings = $ticket->settings ?? [];
            if ($ticket->payment_method === 'okohi_reward' && $ticket->okohi_transaction_id) {
                $ticketSettings['okohi_refund_status'] = 'refund_pending';
            }

            $ticket->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => $request->input('reason'),
                'settings' => $ticketSettings,
            ]);
            DB::commit();

            // Reverse claim on Okohi if paid by reward
            if ($ticket->payment_method === 'okohi_reward' && $ticket->okohi_transaction_id) {
                CancelOrReverseOkohiClaimJob::dispatch($ticket->okohi_transaction_id, 'reverse', tenant('id'), $ticket->id);
            }

            try {
                $trip = Trip::with(['route.routeStopOrders', 'originStation', 'destinationStation', 'vehicle.vehicleType'])->find($tripId);
                if ($trip) {
                    event(new SeatMapUpdated($trip, [
                        ['seat_number' => $seatNumber, 'status' => 'available'],
                    ], 'ticket.cancelled'));
                }
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
        $user = $request->user() ?? auth()->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }
        if (! $user instanceof User || ! in_array($user->role, ['admin', 'supervisor', 'seller', 'accountant'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Votre rôle ne permet pas d’exporter les tickets.',
            ], 403);
        }

        $tickets = app(TicketQueryService::class)
            ->getFilteredTicketsQuery($request, $user)
            ->get();

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
            'data' => $exportData->values(),
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

    private function isServedByCurrentTripAfter(
        Trip $trip,
        string $transferStationId,
        string $destinationStationId,
        TripSegmentService $segments,
    ): bool {
        $indices = $segments->stationIndices($trip);
        $transferIndex = $indices[$transferStationId] ?? null;
        $destinationIndex = $indices[$destinationStationId] ?? null;
        $tripDestinationIndex = $indices[$trip->destination_station_id] ?? null;

        return $transferIndex !== null
            && $destinationIndex !== null
            && $tripDestinationIndex !== null
            && $transferIndex < $destinationIndex
            && $destinationIndex <= $tripDestinationIndex;
    }

    private function errorResponse(Request $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withErrors(['general' => $message]);
    }
}
