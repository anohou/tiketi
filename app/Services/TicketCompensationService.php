<?php

namespace App\Services;

use App\Jobs\CancelOrReverseOkohiClaimJob;
use App\Models\OkohiTicketOutbox;
use App\Models\OperationalSetting;
use App\Models\Ticket;
use App\Models\TicketCompensation;
use App\Models\TicketJourney;
use App\Models\TicketJourneyAssignment;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketCompensationService
{
    public function request(Ticket $ticket, array $data, User $user): TicketCompensation
    {
        return DB::transaction(function () use ($ticket, $data, $user) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (TicketCompensation::where('ticket_id', $ticket->id)->where('status', 'executed')
                ->whereIn('compensation_type', ['refund', 'credit', 'free_rebooking'])->exists()) {
                throw ValidationException::withMessages(['ticket' => 'Une compensation principale a déjà été exécutée pour ce ticket.']);
            }
            $settings = OperationalSetting::current();
            $direct = in_array($user->role, ['admin', 'supervisor'], true)
                || ($user->role === 'seller' && (bool) data_get($settings->settings, 'seller_compensation_enabled', false));
            $amount = (int) ($data['amount'] ?? 0);
            if ($data['compensation_type'] === 'refund' && $amount === 0) {
                $amount = (int) ($ticket->amount_collected ?? $ticket->price);
            }
            if ($data['compensation_type'] === 'refund'
                && $amount > (int) ($ticket->amount_collected ?? $ticket->price)) {
                throw ValidationException::withMessages([
                    'amount' => 'Le remboursement ne peut pas dépasser le montant net encaissé.',
                ]);
            }
            $limit = (int) data_get($settings->settings, 'seller_compensation_max_amount', 0);
            if ($user->role === 'seller' && $limit > 0 && $amount > $limit) {
                $direct = false;
            }

            $compensation = TicketCompensation::create([
                'reference' => 'CMP-'.strtoupper(Str::random(10)),
                'ticket_id' => $ticket->id,
                'ticket_connection_id' => $ticket->connection?->id,
                'incident_type' => $data['incident_type'],
                'compensation_type' => $data['compensation_type'],
                'amount' => $amount,
                'status' => $direct ? 'executed' : 'pending_approval',
                'reason' => $data['reason'],
                'requested_by' => $user->id,
                'approved_by' => $direct ? $user->id : null,
                'executed_by' => $direct ? $user->id : null,
                'approved_at' => $direct ? now() : null,
                'executed_at' => $direct ? now() : null,
                'replacement_trip_id' => $data['replacement_trip_id'] ?? null,
                'replacement_seat_number' => $data['replacement_seat_number'] ?? null,
            ]);
            if ($direct) {
                $this->applyCompensation($compensation, $ticket);
            }

            return $compensation->fresh(['ticket', 'replacementTrip']);
        });
    }

    public function approve(TicketCompensation $compensation, User $user): TicketCompensation
    {
        if (! in_array($user->role, ['admin', 'supervisor'], true)) {
            abort(403);
        }

        return DB::transaction(function () use ($compensation, $user) {
            $compensation = TicketCompensation::whereKey($compensation->id)->lockForUpdate()->firstOrFail();
            if ($compensation->status !== 'pending_approval') {
                throw ValidationException::withMessages(['compensation' => 'Cette compensation a déjà été traitée.']);
            }
            $compensation->update(['status' => 'executed', 'approved_by' => $user->id, 'executed_by' => $user->id, 'approved_at' => now(), 'executed_at' => now()]);
            $this->applyCompensation($compensation, $compensation->ticket);

            return $compensation->fresh(['ticket', 'replacementTrip']);
        });
    }

    private function applyCompensation(TicketCompensation $compensation, Ticket $ticket): void
    {
        if ($compensation->compensation_type === 'refund') {
            $this->applyRefund($compensation, $ticket);

            return;
        }

        $this->applyTravelEntitlement($compensation, $ticket);
    }

    private function applyRefund(TicketCompensation $compensation, Ticket $ticket): void
    {
        $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        $settings = $ticket->settings ?? [];
        data_set($settings, 'refund.reference', $compensation->reference);
        data_set($settings, 'refund.amount', (int) $compensation->amount);
        data_set($settings, 'refund.executed_at', now()->toIso8601String());

        if ($ticket->payment_method === 'okohi_reward' && $ticket->okohi_transaction_id) {
            data_set($settings, 'okohi_refund_status', 'refund_pending');
        }

        TripSeatOccupancy::query()->where('ticket_id', $ticket->id)->delete();
        $ticket->connection()->update(['status' => 'cancelled']);

        // Point E : un remboursement complet annule TOUS les droits non
        // consommés dans la même transaction. Un droit déjà embarqué reste
        // dans son état historique (jamais réécrit silencieusement).
        $ticket->journeys()->lockForUpdate()->get()->each(function (TicketJourney $journey) {
            if (in_array($journey->status, [
                TicketJourney::STATUS_PENDING,
                TicketJourney::STATUS_AWAITING_TRIP,
                TicketJourney::STATUS_READY,
                TicketJourney::STATUS_ASSIGNED,
            ], true)) {
                $journey->update([
                    'status' => TicketJourney::STATUS_CANCELLED,
                    'settings' => array_merge($journey->settings ?? [], [
                        'cancelled_by_refund' => true,
                    ]),
                ]);

                // Historique d'affectation conservé : on consigne le retrait.
                TicketJourneyAssignment::create([
                    'ticket_journey_id' => $journey->id,
                    'previous_trip_id' => $journey->trip_id,
                    'new_trip_id' => null,
                    'previous_seat_number' => $journey->seat_number,
                    'new_seat_number' => null,
                    'reason' => 'full_refund',
                    'mode' => TicketJourneyAssignment::MODE_MANUAL,
                ]);
            }
        });

        $ticket->update([
            'status' => 'refunded',
            'settings' => $settings,
        ]);

        // Publication Okohi après commit : les statuts remboursés sont visibles
        // (jamais bloquante pour le remboursement).
        DB::afterCommit(function () use ($ticket) {
            try {
                app(OkohiTicketPublisher::class)->enqueue(
                    $ticket,
                    OkohiTicketOutbox::OPERATION_UPDATE,
                );
            } catch (\Throwable $e) {
                // La file Okohi ne doit jamais faire échouer un remboursement.
                Log::warning('Okohi enqueue échoué après remboursement', ['ticket' => $ticket->id]);
            }
        });

        if ($ticket->payment_method === 'okohi_reward' && $ticket->okohi_transaction_id) {
            $tenantId = function_exists('tenancy') && tenancy()->initialized ? (string) tenant('id') : null;
            DB::afterCommit(fn () => CancelOrReverseOkohiClaimJob::dispatch(
                $ticket->okohi_transaction_id,
                'reverse',
                $tenantId,
                $ticket->id,
            ));
        }
    }

    private function applyTravelEntitlement(TicketCompensation $compensation, Ticket $ticket): void
    {
        if ($compensation->compensation_type !== 'free_rebooking') {
            return;
        }
        $trip = Trip::with(['route.routeStopOrders', 'vehicle.vehicleType'])
            ->whereKey($compensation->replacement_trip_id)
            ->lockForUpdate()
            ->firstOrFail();
        if (in_array($trip->status, ['departed', 'arrived', 'cancelled'], true)) {
            throw ValidationException::withMessages(['replacement_trip_id' => 'Le voyage de remplacement n’est plus disponible.']);
        }

        $seatNumber = (int) $compensation->replacement_seat_number;
        $capacity = $trip->vehicle?->vehicleType?->seat_count ?? $trip->vehicle?->seat_count ?? 0;
        if ($seatNumber < 1 || $seatNumber > $capacity) {
            throw ValidationException::withMessages(['replacement_seat_number' => 'Cette place n’existe pas dans le véhicule de remplacement.']);
        }

        $segments = app(TripSegmentService::class);
        [$valid, $error, $indices, $start, $end] = $segments->validateSegment(
            $trip,
            $trip->origin_station_id,
            $trip->destination_station_id,
        );
        if (! $valid) {
            throw ValidationException::withMessages(['replacement_trip_id' => $error]);
        }

        $occupancies = TripSeatOccupancy::with('ticket')
            ->where('trip_id', $trip->id)
            ->where('seat_number', $seatNumber)
            ->get();
        if ($segments->overlappingSeatNumbers($occupancies, $indices, $start, $end) !== []) {
            throw ValidationException::withMessages(['replacement_seat_number' => 'Ce siège est déjà occupé sur le voyage de remplacement.']);
        }
        TripSeatOccupancy::create([
            'trip_id' => $trip->id, 'ticket_id' => $ticket->id,
            'seat_number' => $seatNumber,
            'from_station_id' => $trip->origin_station_id,
            'to_station_id' => $trip->destination_station_id,
        ]);
    }
}
