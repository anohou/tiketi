<?php

namespace App\Http\Controllers\Api;

use App\Events\OkohiClaimUpdated;
use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\CancelOrReverseOkohiClaimJob;
use App\Models\OkohiRewardRequest;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketSetting;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Services\TripSegmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OkohiWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Multi-tenant routing. New integrations include the tenant in the
        // callback URL; the signature scan keeps older webhook URLs working.
        $tenantId = $request->query('tenant');
        if ($tenantId) {
            try {
                tenancy()->initialize($tenantId);
            } catch (\Throwable $e) {
                if (! app()->runningUnitTests()) {
                    return response()->json(['valid' => false, 'message' => 'Invalid tenant: '.$e->getMessage()], 400);
                }
            }
        } elseif (! app()->runningUnitTests()) {
            $body = $request->getContent();
            $receivedSignature = (string) $request->header('X-Okohi-Signature');
            $matchedTenant = null;

            if ($receivedSignature !== '') {
                foreach (Tenant::cursor() as $tenant) {
                    $tenant->run(function () use ($body, $receivedSignature, $tenant, &$matchedTenant) {
                        $tenantSettings = TicketSetting::getSettings();
                        $secret = (string) $tenantSettings->okohi_integration_key;

                        if ($secret !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $receivedSignature)) {
                            $matchedTenant = $tenant;
                        }
                    });

                    if ($matchedTenant) {
                        break;
                    }
                }
            }

            if (! $matchedTenant) {
                return response()->json(['valid' => false, 'message' => 'Missing or invalid tenant context'], 401);
            }

            tenancy()->initialize($matchedTenant);
        }

        $settings = TicketSetting::getSettings();
        $secret = (string) $settings->okohi_integration_key;
        $receivedSignature = (string) $request->header('X-Okohi-Signature');
        $legacyKey = (string) $request->header('X-Okohi-Integration-Key');
        $validSignature = $secret !== ''
            && $receivedSignature !== ''
            && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $receivedSignature);
        $validLegacyKey = $secret !== '' && $legacyKey !== '' && hash_equals($secret, $legacyKey);

        if (! $validSignature && ! $validLegacyKey) {
            return response()->json(['valid' => false, 'message' => 'Unauthorized'], 401);
        }

        // 2. Validate payload from Okohi (allowing either old format or new structure)
        $validated = $request->validate([
            'claim_id' => 'nullable|uuid',
            'partner_reference' => 'nullable|uuid',
            'transaction_id' => 'nullable|string', // legacy format
            'status' => 'required|string|in:approved,confirmed,rejected,cancelled,expired',
            'reward' => 'nullable|array',
            'reward.benefit_type' => 'required_with:reward|string|in:free_ticket,percentage_discount,fixed_discount',
            'reward.benefit_value' => [
                'required_with:reward',
                'integer',
                function ($attribute, $value, $fail) use ($request) {
                    $type = $request->input('reward.benefit_type');
                    if ($type === 'percentage_discount') {
                        if ($value < 1 || $value > 100) {
                            $fail('The benefit value must be between 1 and 100 for a percentage discount.');
                        }
                    } elseif ($type === 'fixed_discount') {
                        if ($value < 0) {
                            $fail('The benefit value must be greater than or equal to 0 for a fixed discount.');
                        }
                    }
                },
            ],
            'discount_amount' => 'nullable|integer|min:0', // legacy override
            'amount_collected' => 'nullable|integer|min:0', // legacy override
        ]);

        // 3. Status Translation & Claim lookup
        $claimId = $validated['claim_id'] ?? $validated['transaction_id'] ?? null;
        $partnerRef = $validated['partner_reference'] ?? null;

        $rewardRequest = OkohiRewardRequest::where(function ($q) use ($partnerRef, $claimId) {
            if ($partnerRef) {
                $q->where('id', $partnerRef);
            }
            if ($claimId) {
                $q->orWhere('okohi_transaction_id', $claimId);
            }
        })->first();

        if (! $rewardRequest) {
            return response()->json(['valid' => false, 'message' => 'Request not found'], 444);
        }

        // Translate 'approved' (Okohi) -> 'confirmed' (Tiketi)
        $targetStatus = $validated['status'];
        if ($targetStatus === 'approved') {
            $targetStatus = 'confirmed';
        }

        // If request is already resolved, return success (idempotent)
        if ($rewardRequest->status !== 'pending' && $rewardRequest->status !== 'failed') {
            return response()->json([
                'valid' => true,
                'message' => 'Request already processed',
                'status' => $rewardRequest->status,
                'ticket_id' => $rewardRequest->ticket_id,
            ]);
        }

        if ($targetStatus === 'confirmed') {
            try {
                DB::beginTransaction();

                // Re-fetch with lock
                $rewardRequest = OkohiRewardRequest::where('id', $rewardRequest->id)
                    ->lockForUpdate()
                    ->first();

                if ($rewardRequest->status !== 'pending' && $rewardRequest->status !== 'failed') {
                    DB::rollBack();

                    return response()->json(['valid' => true, 'message' => 'Request already processed']);
                }

                // If claimId is present, we make sure it's saved in the request
                if ($claimId && ! $rewardRequest->okohi_transaction_id) {
                    $rewardRequest->update(['okohi_transaction_id' => $claimId]);
                }

                $trip = Trip::with(['vehicle.vehicleType'])->findOrFail($rewardRequest->trip_id);

                // Compute commercial price
                $pricePerSeat = app(TripSegmentService::class)->fareAmount($rewardRequest->from_station_id, $rewardRequest->to_station_id);
                if ($pricePerSeat === null) {
                    throw new \Exception('Fare not found between these stations');
                }

                $grossAmount = $pricePerSeat;

                // 4. Calculate structured benefit amounts
                $rewardData = $validated['reward'] ?? null;
                if ($rewardData && isset($rewardData['benefit_type'])) {
                    $computed = $this->calculateAmounts($rewardData, $grossAmount);
                } else {
                    $rewardTitle = $rewardRequest->response_payload['reward']['title'] ?? $rewardRequest->reward_id;
                    $computed = $this->calculateRegexAmounts($rewardTitle, $grossAmount);
                }

                $discountAmount = $validated['discount_amount'] ?? $computed['discount_amount'];
                $amountCollected = $validated['amount_collected'] ?? $computed['amount_collected'];

                if ($amountCollected > 0) {
                    // Update request status to approved_pending_cash
                    $responsePayload = $rewardRequest->response_payload ?? [];
                    $responsePayload['computed_discount_amount'] = $discountAmount;
                    $responsePayload['computed_amount_collected'] = $amountCollected;

                    $rewardRequest->update([
                        'status' => 'approved_pending_cash',
                        'response_payload' => $responsePayload,
                        'expires_at' => now()->addMinutes(30),
                    ]);

                    // Extend hold in TripSeatOccupancy to 30 minutes
                    TripSeatOccupancy::where('okohi_reward_request_id', $rewardRequest->id)
                        ->update([
                            'expires_at' => now()->addMinutes(30),
                        ]);

                    DB::commit();

                    $this->broadcastClaimUpdate($claimId, 'approved', array_merge($request->all(), [
                        'local_status' => 'approved_pending_cash',
                        'amount_collected' => $amountCollected,
                    ]), $rewardRequest);

                    return response()->json([
                        'valid' => true,
                        'message' => 'Request approved, pending cash collection',
                        'status' => 'approved_pending_cash',
                        'amount_collected' => $amountCollected,
                    ]);
                }

                // Create ticket immediately (fully free ticket)
                $ticket = $rewardRequest->createTicket($discountAmount, $amountCollected);

                DB::commit();

                $this->broadcastClaimUpdate($claimId, 'approved', array_merge($request->all(), [
                    'local_status' => 'confirmed',
                    'ticket_id' => $ticket->id,
                ]), $rewardRequest);

                return response()->json([
                    'valid' => true,
                    'message' => 'Ticket created successfully',
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Okohi Webhook confirmation failed: '.$e->getMessage());

                $isRetryable = ($e instanceof QueryException &&
                                (str_contains($e->getMessage(), 'Lock wait timeout') ||
                                 str_contains($e->getMessage(), 'deadlock') ||
                                 str_contains($e->getMessage(), 'Connection refused')));

                if ($isRetryable) {
                    $rewardRequest->update([
                        'status' => 'failed',
                        'last_error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'valid' => false,
                        'message' => 'Retryable database error: '.$e->getMessage(),
                    ], 500);
                } else {
                    DB::transaction(function () use ($rewardRequest, $e) {
                        TripSeatOccupancy::where('okohi_reward_request_id', $rewardRequest->id)->delete();
                        $rewardRequest->update([
                            'status' => 'failed',
                            'last_error' => $e->getMessage(),
                        ]);
                    });

                    if ($rewardRequest->okohi_transaction_id) {
                        CancelOrReverseOkohiClaimJob::dispatch($rewardRequest->okohi_transaction_id, 'reverse', tenant('id'));
                    }

                    return response()->json([
                        'valid' => true,
                        'message' => 'Non-retryable error, compensation initiated: '.$e->getMessage(),
                        'status' => 'failed',
                    ]);
                }
            }
        } else {
            // Rejected or cancelled
            try {
                DB::beginTransaction();

                // Re-fetch with lock
                $rewardRequest = OkohiRewardRequest::where('id', $rewardRequest->id)
                    ->lockForUpdate()
                    ->first();

                if ($rewardRequest->status !== 'pending') {
                    DB::rollBack();

                    return response()->json(['valid' => true, 'message' => 'Request already processed']);
                }

                // Delete hold
                TripSeatOccupancy::where('okohi_reward_request_id', $rewardRequest->id)->delete();

                $rewardRequest->update([
                    'status' => $targetStatus,
                ]);

                DB::commit();

                $this->broadcastClaimUpdate($claimId, $targetStatus, $request->all());

                // Broadcast seat map update (released seat)
                try {
                    $broadcastTrip = Trip::with([
                        'route.routeStopOrders',
                        'originStation',
                        'destinationStation',
                        'vehicle.vehicleType',
                    ])->find($rewardRequest->trip_id);

                    if ($broadcastTrip) {
                        event(new SeatMapUpdated(
                            $broadcastTrip,
                            [[
                                'seat_number' => $rewardRequest->seat_number,
                                'status' => 'available',
                            ]],
                            'ticket.cancelled'
                        ));
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast seat release: '.$e->getMessage());
                }

                return response()->json([
                    'valid' => true,
                    'message' => 'Hold released successfully',
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Okohi Webhook cancel/reject failed: '.$e->getMessage());

                return response()->json(['valid' => false, 'message' => $e->getMessage()], 500);
            }
        }
    }

    private function calculateAmounts(array $rewardData, int $grossAmount): array
    {
        $benefitType = $rewardData['benefit_type'] ?? 'free_ticket';
        $benefitValue = $rewardData['benefit_value'] ?? 100;

        $discountAmount = $grossAmount; // Default: 100% discount
        $amountCollected = 0;

        if ($benefitType === 'percentage_discount') {
            $discountAmount = (int) ($grossAmount * ($benefitValue / 100.0));
            $amountCollected = $grossAmount - $discountAmount;
        } elseif ($benefitType === 'fixed_discount') {
            $discountAmount = min($grossAmount, (int) $benefitValue);
            $amountCollected = $grossAmount - $discountAmount;
        } elseif ($benefitType === 'free_ticket') {
            $discountAmount = $grossAmount;
            $amountCollected = 0;
        }

        return [
            'discount_amount' => $discountAmount,
            'amount_collected' => $amountCollected,
        ];
    }

    private function broadcastClaimUpdate(?string $claimId, string $status, array $payload, ?OkohiRewardRequest $rewardRequest = null): void
    {
        if (! $claimId) {
            return;
        }

        try {
            event(new OkohiClaimUpdated(
                claimId: $claimId,
                status: $status,
                payload: $payload,
                tenantId: tenancy()->initialized ? tenant('id') : null,
                tripId: $rewardRequest?->trip_id,
                seatNumber: $rewardRequest?->seat_number
            ));
        } catch (\Throwable $exception) {
            Log::warning('Unable to broadcast Okohi claim update: '.$exception->getMessage());
        }
    }

    private function calculateRegexAmounts(string $rewardTitle, int $grossAmount): array
    {
        $discountAmount = $grossAmount; // Default: 100% discount
        $amountCollected = 0;

        if (preg_match('/50\s*%/i', $rewardTitle)) {
            $discountAmount = (int) ($grossAmount * 0.5);
            $amountCollected = $grossAmount - $discountAmount;
        } elseif (preg_match('/25\s*%/i', $rewardTitle)) {
            $discountAmount = (int) ($grossAmount * 0.25);
            $amountCollected = $grossAmount - $discountAmount;
        } elseif (preg_match('/75\s*%/i', $rewardTitle)) {
            $discountAmount = (int) ($grossAmount * 0.75);
            $amountCollected = $grossAmount - $discountAmount;
        }

        return [
            'discount_amount' => $discountAmount,
            'amount_collected' => $amountCollected,
        ];
    }
}
