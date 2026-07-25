<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Jobs\CancelOrReverseOkohiClaimJob;
use App\Models\OkohiRewardRequest;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Services\TripSegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OkohiRewardRequestController extends Controller
{
    public function store(Request $request, TripSegmentService $segments)
    {
        $validated = $request->validate([
            'trip_id' => 'required|uuid|exists:trips,id',
            'from_station_id' => 'required|uuid|exists:stations,id',
            'to_station_id' => 'required|uuid|exists:stations,id',
            'seat_number' => 'required|integer|min:1',
            'customer_number' => 'required|string',
            'reward_id' => 'required|string',
            'idempotency_key' => 'required|string',
            'final_destination_station_id' => 'nullable|uuid|exists:stations,id',
            'connection_route_id' => 'nullable|uuid|exists:routes,id',
            'passenger_name' => 'nullable|string|max:255',
            'passenger_phone' => 'nullable|string|max:50',
        ]);

        // Enforce idempotency & prevent duplicate Okohi requests for active seat holds
        $existing = OkohiRewardRequest::where('idempotency_key', $validated['idempotency_key'])->first();
        if ($existing) {
            return response()->json($existing);
        }

        $existingPending = OkohiRewardRequest::where('trip_id', $validated['trip_id'])
            ->where('seat_number', $validated['seat_number'])
            ->whereIn('status', ['pending', 'approved_pending_cash'])
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($existingPending) {
            if ($existingPending->customer_number === $validated['customer_number']) {
                return response()->json($existingPending, 200);
            }

            return response()->json(['error' => 'Ce siège fait déjà l\'objet d\'une demande en cours.'], 409);
        }

        try {
            DB::beginTransaction();

            $trip = Trip::where('id', $validated['trip_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Segment validation
            [$validSegment, $segmentError, $stationIndices, $reqStartIndex, $reqEndIndex] = $segments->validateSegment(
                $trip,
                $validated['from_station_id'],
                $validated['to_station_id']
            );

            if (! $validSegment) {
                DB::rollBack();

                return response()->json(['error' => $segmentError], 422);
            }

            // Check if seat exists on the vehicle
            if ($validated['seat_number'] > $trip->vehicle->seat_count) {
                DB::rollBack();

                return response()->json(['error' => "Ce siège n'existe pas sur le véhicule."], 422);
            }

            // Check if seat is already occupied or held
            $occupiedSeats = $segments->occupiedSeatsForSegment($trip, $validated['from_station_id'], $validated['to_station_id']);
            if (in_array((int) $validated['seat_number'], $occupiedSeats, true)) {
                DB::rollBack();

                return response()->json(['error' => 'Ce siège est déjà occupé ou réservé.'], 409);
            }

            $settings = TicketSetting::getSettings();
            if (! $settings->hasOkohiIntegration()) {
                DB::rollBack();

                return response()->json(['error' => 'L\'intégration Okohi n\'est pas configurée.'], 400);
            }

            $expiresAt = now()->addMinutes(5);

            $rewardRequest = OkohiRewardRequest::create([
                'seller_id' => auth()->id(),
                'trip_id' => $validated['trip_id'],
                'from_station_id' => $validated['from_station_id'],
                'to_station_id' => $validated['to_station_id'],
                'seat_number' => $validated['seat_number'],
                'customer_number' => $validated['customer_number'],
                'reward_id' => $validated['reward_id'],
                'idempotency_key' => $validated['idempotency_key'],
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ]);

            // Create temporary seat hold
            TripSeatOccupancy::create([
                'trip_id' => $validated['trip_id'],
                'seat_number' => $validated['seat_number'],
                'from_station_id' => $validated['from_station_id'],
                'to_station_id' => $validated['to_station_id'],
                'okohi_reward_request_id' => $rewardRequest->id,
                'expires_at' => $expiresAt,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create OkohiRewardRequest: '.$e->getMessage());

            return response()->json(['error' => 'Erreur lors de l\'initialisation de la demande.'], 500);
        }

        // Call Okohi partner API
        $baseUrl = rtrim(config('services.okohi.base_url'), '/');
        try {
            $response = Http::timeout(15)
                ->withHeader('X-Okohi-Integration-Key', $settings->okohi_integration_key)
                ->post("{$baseUrl}/api/v1/partner/customers/{$validated['customer_number']}/grant-reward", [
                    'reward_id' => $validated['reward_id'],
                    'partner_reference' => $rewardRequest->id,
                    'expires_at' => $expiresAt->toIso8601String(),
                ]);

            $body = $response->json();

            if (! $response->successful()) {
                throw new \Exception($body['message'] ?? 'Okohi API error: '.$response->status());
            }

            // Save external transaction ID
            $okohiTxId = data_get($body, 'data.claim.id') ?? $body['transaction_id'] ?? $body['id'] ?? $rewardRequest->id;
            $rewardRequest->update([
                'okohi_transaction_id' => $okohiTxId,
                'request_payload' => array_filter([
                    'reward_id' => $validated['reward_id'],
                    'final_destination_station_id' => $validated['final_destination_station_id'] ?? null,
                    'connection_route_id' => $validated['connection_route_id'] ?? null,
                    'passenger_name' => $validated['passenger_name'] ?? null,
                    'passenger_phone' => $validated['passenger_phone'] ?? null,
                ]),
                'response_payload' => $body,
            ]);

            return response()->json($rewardRequest, 202);

        } catch (\Exception $e) {
            Log::error('Okohi grant-reward API failed: '.$e->getMessage());

            $isDuplicateClaim = str_contains($e->getMessage(), 'already has a pending claim');

            if ($isDuplicateClaim) {
                // Customer already has an active pending claim on Okohi side.
                // Keep the seat hold active on Tiketi side to preserve alignment.
                $rewardRequest->update([
                    'status' => 'pending',
                    'last_error' => null,
                ]);

                return response()->json($rewardRequest, 202);
            }

            DB::beginTransaction();
            // Release the hold on true failure
            TripSeatOccupancy::where('okohi_reward_request_id', $rewardRequest->id)->delete();
            $rewardRequest->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ]);
            DB::commit();

            return response()->json(['error' => 'Échec d\'envoi de la demande à Okohi: '.$e->getMessage()], 502);
        }
    }

    public function show(OkohiRewardRequest $request)
    {
        // Restrict access to requests of the same seller or admin
        $user = auth()->user();
        if ($user->role === 'seller' && $request->seller_id !== $user->id) {
            abort(403, 'Non autorisé.');
        }

        $oldStatus = null;
        $shouldCancelOrReverse = DB::transaction(function () use ($request, &$oldStatus) {
            $req = OkohiRewardRequest::where('id', $request->id)
                ->lockForUpdate()
                ->first();

            if ($req && in_array($req->status, ['pending', 'approved_pending_cash']) && $req->expires_at->isPast()) {
                $oldStatus = $req->status;
                TripSeatOccupancy::where('okohi_reward_request_id', $req->id)->delete();
                $req->update(['status' => 'expired']);

                return true;
            }

            return false;
        });

        if ($shouldCancelOrReverse) {
            $request->refresh();
            if ($request->okohi_transaction_id) {
                $action = $oldStatus === 'approved_pending_cash' ? 'reverse' : 'cancel';
                CancelOrReverseOkohiClaimJob::dispatch($request->okohi_transaction_id, $action, tenant('id'));
            }
        }

        return response()->json($request);
    }

    public function destroy(OkohiRewardRequest $request)
    {
        // Vendeur can cancel pending or approved_pending_cash requests
        $user = auth()->user();
        if ($user->role === 'seller' && $request->seller_id !== $user->id) {
            abort(403, 'Non autorisé.');
        }

        $oldStatus = null;

        try {
            $shouldCancelOrReverse = DB::transaction(function () use ($request, &$oldStatus) {
                $req = OkohiRewardRequest::where('id', $request->id)
                    ->lockForUpdate()
                    ->first();

                if (! $req || ! in_array($req->status, ['pending', 'approved_pending_cash'])) {
                    throw new \Exception('Seules les demandes en attente ou en attente d\'encaissement peuvent être annulées.');
                }

                $oldStatus = $req->status;
                TripSeatOccupancy::where('okohi_reward_request_id', $req->id)->delete();
                $req->update(['status' => 'rejected']);

                return true;
            });

            if ($shouldCancelOrReverse) {
                $request->refresh();
                if ($request->okohi_transaction_id) {
                    $action = $oldStatus === 'approved_pending_cash' ? 'reverse' : 'cancel';
                    CancelOrReverseOkohiClaimJob::dispatch($request->okohi_transaction_id, $action, tenant('id'));
                }
            }

            return response()->json(['message' => 'Demande annulée avec succès. Le siège a été libéré.']);
        } catch (\Exception $e) {
            Log::error('Failed to cancel OkohiRewardRequest: '.$e->getMessage());

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function confirmCash(OkohiRewardRequest $request)
    {
        $user = auth()->user();
        if ($user->role === 'seller' && $request->seller_id !== $user->id) {
            abort(403, 'Non autorisé.');
        }

        try {
            $ticket = DB::transaction(function () use ($request) {
                // Re-fetch request with pessimistic lock
                $req = OkohiRewardRequest::where('id', $request->id)
                    ->lockForUpdate()
                    ->first();

                if (! $req || $req->status !== 'approved_pending_cash') {
                    throw new \Exception('Cette demande n\'est pas ou plus en attente d\'encaissement.');
                }

                // Verify the hold still exists and is valid
                $hold = TripSeatOccupancy::where('okohi_reward_request_id', $req->id)
                    ->where('trip_id', $req->trip_id)
                    ->where('seat_number', $req->seat_number)
                    ->lockForUpdate()
                    ->first();

                if (! $hold) {
                    throw new \Exception('Le blocage temporaire du siège n\'existe plus.');
                }

                if ($hold->expires_at && $hold->expires_at->isPast()) {
                    throw new \Exception('Le blocage temporaire du siège a expiré.');
                }

                $discountAmount = $req->response_payload['computed_discount_amount'] ?? 0;
                $amountCollected = $req->response_payload['computed_amount_collected'] ?? 0;

                return $req->createTicket($discountAmount, $amountCollected);
            });

            return response()->json([
                'success' => true,
                'message' => 'Encaissement confirmé et ticket émis.',
                'ticket_id' => $ticket->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Okohi confirmCash failed: '.$e->getMessage());

            return response()->json(['error' => 'Échec de l\'émission du ticket: '.$e->getMessage()], 422);
        }
    }

    public function pendingForSeat(Request $request)
    {
        $validated = $request->validate([
            'trip_id' => 'required|uuid|exists:trips,id',
            'seat_number' => 'required|integer',
        ]);

        $pending = OkohiRewardRequest::where('trip_id', $validated['trip_id'])
            ->where('seat_number', $validated['seat_number'])
            ->whereIn('status', ['pending', 'approved_pending_cash'])
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        return response()->json($pending);
    }

    public function pendingForTrip(Request $request)
    {
        $validated = $request->validate([
            'trip_id' => 'required|uuid|exists:trips,id',
        ]);

        $requests = OkohiRewardRequest::where('trip_id', $validated['trip_id'])
            ->whereIn('status', ['pending', 'approved_pending_cash'])
            ->where('expires_at', '>', now())
            ->get();

        return response()->json($requests);
    }
}
