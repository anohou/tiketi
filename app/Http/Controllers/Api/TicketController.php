<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Services\SeatAllocator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        // Load route stop orders to determine sequence
        $trip = Trip::with(['route.routeStopOrders', 'tripSeatOccupancies.ticket'])->findOrFail($validated['trip_id']);
        
        // Determine segment indices from RouteStopOrders
        // Map station_id => stop_index
        $stationIndices = $trip->route->routeStopOrders->pluck('stop_index', 'station_id')->toArray();
        
        $fromStationId = $validated['from_station_id'];
        $toStationId = $validated['to_station_id'];
        
        $reqStartIndex = $stationIndices[$fromStationId] ?? false;
        $reqEndIndex = $stationIndices[$toStationId] ?? false;
        
        // Safety check for stops validity
        // Logic depends on direction? Assuming standard forward route for now OR bidirectional handled by indices logic?
        // Indices are 0..N.
        // If route is A->B->C (0,1,2). Trip A->B (0->1).
        if ($reqStartIndex === false || $reqEndIndex === false) {
             return response()->json(['message' => 'Segment d\'itinéraire invalide (gares non trouvées sur la route).'], 422);
        }
        
        // Handle bidirectional validation if needed, but usually indices check suffices:
        // Start must be != End.
        if ($reqStartIndex == $reqEndIndex) {
            return response()->json(['message' => 'Gare de départ et d\'arrivée identiques.'], 422);
        }
        
        // Determine direction based on indices. 
        // If Start < End : Forward. 
        // If Start > End : Backward (if supported).
        // Let's assume strict validation for now:
        // Actually, for bidirectional routes, we might need more logic or just trust indices if route supports it.
        // Ekkou usually does unidirectional routes? Or explicit Forward/Return routes?
        // Assuming Forward:
        $isForward = $reqStartIndex < $reqEndIndex;
        // If backward is allowed, we'd handle it. BUT usually routes are A->B.
        // If user tries B->A on A->B route, it should fail unless bidirectional trip.
        // For now, let's enforce Start < End if standard.
        // Note: Trip model might have 'is_bidirectional' or similar?
        // Let's stick to strict index comparison for overlap check validity.
        // Overlap logic works on [min, max] range? No, directional.
        // Let's assume Forward only for this refactor to be safe, unless we see evidence otherwise.
        if (!$isForward) {
             return response()->json(['message' => 'Sens du trajet invalide (Départ après Arrivée).'], 422);
        }

        // Restriction station vendeur
        $user = auth()->user();
        if ($user->role === 'seller') {
            $assignedStationIds = $user->stationAssignments()->where('active', true)->pluck('station_id')->toArray();
            
            if (!in_array($fromStationId, $assignedStationIds)) {
                return response()->json([
                    'message' => 'Vous n\'êtes pas autorisé à vendre des tickets au départ de cette station.'
                ], 403);
            }

            // Vérification du contrôle des ventes pour les stations intermédiaires
            $isAtOriginStation = in_array($trip->route->origin_station_id, $assignedStationIds);
            
            if (!$isAtOriginStation && $trip->isSalesClosed()) {
                // Sur un voyage fermé, vérifier si les places demandées sont libérées à cette station
                $seatsFreedAtThisStation = $trip->tripSeatOccupancies
                    ->filter(function ($occupancy) use ($fromStationId) {
                        // Une place est "libérée" si le ticket se termine à notre station de départ
                        return $occupancy->ticket && $occupancy->ticket->to_station_id === $fromStationId;
                    })
                    ->pluck('seat_number')
                    ->toArray();
                
                $requestedSeats = $validated['seats'];
                $seatsNotFreed = array_diff($requestedSeats, $seatsFreedAtThisStation);
                
                if (!empty($seatsNotFreed)) {
                    return response()->json([
                        'message' => 'Ce voyage est fermé aux ventes intermédiaires. Vous ne pouvez vendre que les places libérées à votre gare.'
                    ], 403);
                }
            }
        }

        // Get occupied seats for this segment
        $occupiedSeats = $trip->tripSeatOccupancies->filter(function ($occupancy) use ($stationIndices, $reqStartIndex, $reqEndIndex) {
            if (!$occupancy->ticket) return false;
            
            $ticketFromId = $occupancy->ticket->from_station_id;
            $ticketToId = $occupancy->ticket->to_station_id;
            
            $ticketFromIdx = $stationIndices[$ticketFromId] ?? null;
            $ticketToIdx = $stationIndices[$ticketToId] ?? null;
            
            if ($ticketFromIdx === null || $ticketToIdx === null) return true; // Conflicting/Unknown, treat as occupied safe bet
            
            // Overlap check: Start1 < End2 && Start2 < End1
            return ($ticketFromIdx < $reqEndIndex) && ($reqStartIndex < $ticketToIdx);
        })->pluck('seat_number')->toArray();

        $conflictingSeats = array_intersect($validated['seats'], $occupiedSeats);
        if (!empty($conflictingSeats)) {
            $msg = 'Certaines places sont déjà occupées pour ce segment: ' . implode(', ', $conflictingSeats);
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg], 422);
            }
            return back()->withErrors(['general' => $msg]);
        }

        // Vérifier que les places existent
        if (max($validated['seats']) > $trip->vehicle->seat_count) {
            $msg = 'Certaines places n\'existent pas';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg], 422);
            }
            return back()->withErrors(['general' => $msg]);
        }

        try {
            DB::beginTransaction();

            $tickets = [];
            foreach ($validated['seats'] as $seatNumber) {
                $ticket = Ticket::create([
                    'ticket_number' => 'TKT-' . strtoupper(Str::random(8)),
                    'trip_id' => $trip->id,
                    'vehicle_id' => $trip->vehicle_id,
                    'from_station_id' => $validated['from_station_id'],
                    'to_station_id' => $validated['to_station_id'],
                    'seat_number' => $seatNumber,
                    'passenger_name' => $validated['passenger_name'] ?? 'Passager',
                    'passenger_phone' => $validated['passenger_phone'] ?? '',
                    'price' => $validated['amount'] / count($validated['seats']), // Prix par place
                    'seller_id' => auth()->id(),
                    'station_id' => auth()->user()->stationAssignments()->where('active', true)->first()?->station_id,
                    'qr_code' => 'QR-' . strtoupper(Str::random(12)),
                ]);

                // Marquer la place comme occupée
                TripSeatOccupancy::create([
                    'trip_id' => $trip->id,
                    'seat_number' => $seatNumber,
                    'ticket_id' => $ticket->id,
                ]);

                $tickets[] = $ticket;
            }

            DB::commit();

            // TODO: Broadcast seat map update via Reverb
            // event(new SeatMapUpdated($trip->id));

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Tickets créés avec succès',
                    'tickets' => $tickets,
                    'total_amount' => $validated['amount'],
                    'print_url' => route('tickets.print-multiple'),
                    'ticket_ids' => collect($tickets)->pluck('id')->toArray()
                ], 201);
            }
            
            
            // Retourner une réponse Inertia avec flash pour impression
            return redirect()->back()->with([
                'flash' => [
                    'ticket_id' => $tickets[0]->id, // Premier ticket pour impression
                    'ticket_ids' => collect($tickets)->pluck('id')->toArray(),
                    'message' => 'Ticket créé avec succès'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création ticket: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Erreur lors de la création des tickets: ' . $e->getMessage()
                ], 500);
            }
            
            return back()->withErrors([
                'general' => 'Erreur lors de la création des tickets: ' . $e->getMessage()
            ]);
        }
    }

    public function show(Ticket $ticket)
    {
        try {
            $ticket->load(['trip.route', 'trip.vehicle', 'fromStation', 'toStation', 'seller']);
            
            $settings = null;
            try {
                $settings = \App\Models\TicketSetting::getSettings();
            } catch (\Exception $e) {
                \Log::error('Failed to get settings: ' . $e->getMessage());
                // If settings retrieval fails, use defaults
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
        } catch (\Exception $e) {
            \Log::error('Error in TicketController@show: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Ticket $ticket)
    {
        if ($ticket->seller_id !== auth()->id()) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        try {
            DB::beginTransaction();

            // Libérer la place
            TripSeatOccupancy::where('ticket_id', $ticket->id)->delete();
            
            // Supprimer le ticket
            $ticket->delete();

            DB::commit();

            // TODO: Broadcast seat map update via Reverb
            // event(new SeatMapUpdated($ticket->trip_id));

            return response()->json(['message' => 'Ticket annulé avec succès']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erreur lors de l\'annulation'], 500);
        }
    }
}
