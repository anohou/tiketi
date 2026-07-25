<?php

namespace App\Models;

use App\Events\SeatMapUpdated;
use App\Services\OptimisationService;
use App\Services\TripSegmentService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OkohiRewardRequest extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'seller_id',
        'trip_id',
        'from_station_id',
        'to_station_id',
        'seat_number',
        'customer_number',
        'reward_id',
        'okohi_transaction_id',
        'idempotency_key',
        'status',
        'expires_at',
        'confirmed_at',
        'ticket_id',
        'request_payload',
        'response_payload',
        'last_error',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function fromStation()
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation()
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function createTicket(int $discountAmount, int $amountCollected)
    {
        $trip = Trip::with(['vehicle.vehicleType'])->findOrFail($this->trip_id);

        $seller = $this->seller;
        $sellerStationId = $seller->stationAssignments()->where('active', true)->first()?->station_id ?? $this->from_station_id;

        $optService = app(OptimisationService::class);
        $boardingGroup = $optService->computeBoardingGroup($trip->vehicle->vehicleType, $this->seat_number);

        $finalDestinationId = $this->request_payload['final_destination_station_id'] ?? null;
        $targetDestinationId = $finalDestinationId ?: $this->to_station_id;

        $pricePerSeat = app(TripSegmentService::class)->fareAmount($this->from_station_id, $targetDestinationId);
        if ($pricePerSeat === null) {
            $pricePerSeat = app(TripSegmentService::class)->fareAmount($this->from_station_id, $this->to_station_id) ?? 0;
        }

        $grossAmount = $pricePerSeat;

        return DB::transaction(function () use ($trip, $sellerStationId, $boardingGroup, $grossAmount, $discountAmount, $amountCollected, $finalDestinationId, $targetDestinationId) {
            // Lock and verify hold
            $hold = TripSeatOccupancy::where('okohi_reward_request_id', $this->id)
                ->lockForUpdate()
                ->first();

            if (! $hold) {
                throw new \Exception('Le blocage temporaire du siège est introuvable.');
            }

            if ($hold->expires_at && $hold->expires_at->isPast()) {
                throw new \Exception('Le blocage temporaire du siège a expiré.');
            }

            // Verify that no other ticket occupies this seat on overlapping segments
            $segments = app(TripSegmentService::class);
            [$valid, $msg, $indices, $reqStart, $reqEnd] = $segments->validateSegment($trip, $this->from_station_id, $this->to_station_id);

            if (! $valid) {
                throw new \Exception('Segment d\'itinéraire invalide : '.$msg);
            }

            $otherOccupancies = TripSeatOccupancy::where('trip_id', $this->trip_id)
                ->where('seat_number', $this->seat_number)
                ->where('id', '!=', $hold->id)
                ->with('ticket')
                ->get();

            $hasOverlap = $otherOccupancies->contains(function ($occ) use ($indices, $reqStart, $reqEnd) {
                $ticket = $occ->ticket;
                if ($ticket) {
                    if ($ticket->status === 'cancelled') {
                        return false;
                    }
                } else {
                    $isHeld = $occ->okohi_reward_request_id
                        && $occ->expires_at
                        && $occ->expires_at->isFuture();
                    if (! $isHeld) {
                        return false;
                    }
                }

                $occStart = $indices[$occ->from_station_id ?? $ticket?->from_station_id] ?? null;
                $occEnd = $indices[$occ->to_station_id ?? $ticket?->to_station_id] ?? null;

                if ($occStart === null || $occEnd === null) {
                    return true;
                }

                return $occStart < $reqEnd && $reqStart < $occEnd;
            });

            if ($hasOverlap) {
                throw new \Exception('Ce siège est déjà réservé ou bloqué par une autre demande sur ce trajet.');
            }

            $ticketData = [
                'ticket_number' => 'TKT-'.strtoupper(Str::random(8)),
                'trip_id' => $this->trip_id,
                'vehicle_id' => $trip->vehicle_id,
                'from_station_id' => $this->from_station_id,
                'to_station_id' => $targetDestinationId,
                'seat_number' => $this->seat_number,
                'passenger_name' => $this->response_payload['claim']['customer']['first_name'] ?? $this->response_payload['customer']['name'] ?? 'Client Okohi',
                'passenger_phone' => $this->customer_number,
                'price' => $grossAmount,
                'seller_id' => $this->seller_id,
                'station_id' => $sellerStationId,
                'qr_code' => 'QR-'.strtoupper(Str::random(12)),
                'boarding_group' => $boardingGroup,
                'payment_method' => 'okohi_reward',
                'okohi_customer_number' => $this->customer_number,
                'okohi_reward_id' => $this->reward_id,
                'okohi_transaction_id' => $this->okohi_transaction_id,
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'amount_collected' => $amountCollected,
            ];

            if (!empty($finalDestinationId)) {
                $ticketData['is_connection'] = true;
                $ticketData['transfer_station_id'] = $this->to_station_id;
                $ticketData['connection_route_id'] = $this->request_payload['connection_route_id'] ?? null;
            }

            $ticket = Ticket::create($ticketData);

            $ticket->update(['qr_payload' => $ticket->qrPayloadData()]);

            // Convert hold to permanent occupancy
            $hold->update([
                'ticket_id' => $ticket->id,
                'okohi_reward_request_id' => null,
                'expires_at' => null,
            ]);

            $this->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'ticket_id' => $ticket->id,
            ]);

            try {
                event(new SeatMapUpdated($trip, [
                    ['seat_number' => $this->seat_number, 'status' => 'occupied'],
                ], 'ticket.created'));
            } catch (\Exception $e) {
                \Log::warning('Failed to broadcast SeatMapUpdated: '.$e->getMessage());
            }

            return $ticket;
        });
    }
}
