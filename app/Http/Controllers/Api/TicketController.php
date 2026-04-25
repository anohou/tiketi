<?php

namespace App\Http\Controllers\Api;

use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'trip_id' => 'required|uuid|exists:trips,id',
            'from_station_id' => 'required|uuid|exists:stations,id',
            'to_station_id' => 'required|uuid|exists:stations,id',
            'seats' => 'required|array|min:1',
            'seats.*' => 'integer|min:1',
            'passenger_name' => 'nullable|string|max:255',
            'passenger_phone' => 'nullable|string|max:20',
            'amount' => 'required|integer|min:0',
        ]);

        $trip = Trip::with(['route.routeStopOrders', 'tripSeatOccupancies.ticket', 'vehicle.vehicleType'])->findOrFail($validated['trip_id']);

        $stationIndices = $trip->route->routeStopOrders->pluck('stop_index', 'station_id')->toArray();
        $fromStationId = $validated['from_station_id'];
        $toStationId = $validated['to_station_id'];
        $reqStartIndex = $stationIndices[$fromStationId] ?? false;
        $reqEndIndex = $stationIndices[$toStationId] ?? false;

        if ($reqStartIndex === false || $reqEndIndex === false) {
            return $this->errorResponse($request, 'Segment d\'itinéraire invalide (gares non trouvées sur la route).', 422);
        }

        if ($reqStartIndex == $reqEndIndex) {
            return $this->errorResponse($request, 'Gare de départ et d\'arrivée identiques.', 422);
        }

        if ($reqStartIndex > $reqEndIndex) {
            return $this->errorResponse($request, 'Sens du trajet invalide (Départ après Arrivée).', 422);
        }

        // Restriction station vendeur
        $user = auth()->user();
        if ($user->role === 'seller') {
            $assignedStationIds = $user->stationAssignments()->where('active', true)->pluck('station_id')->toArray();

            if (! in_array($fromStationId, $assignedStationIds)) {
                return $this->errorResponse($request, 'Vous n\'êtes pas autorisé à vendre des tickets au départ de cette station.', 403);
            }

            $isAtOriginStation = in_array($trip->route->origin_station_id, $assignedStationIds);

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
        $occupiedSeats = $trip->tripSeatOccupancies->filter(function ($occupancy) use ($stationIndices, $reqStartIndex, $reqEndIndex) {
            if (! $occupancy->ticket) {
                return false;
            }

            $ticketFromIdx = $stationIndices[$occupancy->ticket->from_station_id] ?? null;
            $ticketToIdx = $stationIndices[$occupancy->ticket->to_station_id] ?? null;

            if ($ticketFromIdx === null || $ticketToIdx === null) {
                return true;
            }

            return ($ticketFromIdx < $reqEndIndex) && ($reqStartIndex < $ticketToIdx);
        })->pluck('seat_number')->toArray();

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

            // Double check occupied seats
            $lockedOccupancies = TripSeatOccupancy::where('trip_id', $trip->id)
                ->whereIn('seat_number', $validated['seats'])
                ->pluck('seat_number')
                ->toArray();

            if (! empty($lockedOccupancies)) {
                DB::rollBack();

                return $this->errorResponse($request, 'Ces places viennent d\'être réservées par un autre agent: '.implode(', ', $lockedOccupancies), 409);
            }

            $sellerStationId = auth()->user()->stationAssignments()->where('active', true)->first()?->station_id;
            $pricePerSeat = $validated['amount'] / count($validated['seats']);

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
                    'total_amount' => $validated['amount'],
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
                'footer_messages' => ['Valable pour ce voyage', 'Non remboursable'],
                'print_qr_code' => false,
                'qr_code_base_url' => null,
            ];
        }

        $ticketArray = $ticket->toArray();
        $ticketArray['settings'] = $settings;

        return response()->json($ticketArray);
    }

    public function destroy(Ticket $ticket)
    {
        if ($ticket->seller_id !== auth()->id()) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $tripId = $ticket->trip_id;
        $seatNumber = $ticket->seat_number;

        try {
            DB::beginTransaction();
            TripSeatOccupancy::where('ticket_id', $ticket->id)->delete();
            $ticket->delete();
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

    private function errorResponse(Request $request, string $message, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return back()->withErrors(['general' => $message]);
    }
}
