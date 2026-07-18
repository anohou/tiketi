<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ticketing\BoardTicket;
use App\Domain\Ticketing\TicketingRuleViolation;
use App\Domain\Ticketing\TripSalesPolicy;
use App\Domain\Trips\CrewTripAccessPolicy;
use App\Events\SeatMapUpdated;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketConnection;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Services\OptimisationService;
use App\Services\TripSegmentService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrewTicketController extends Controller
{
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'qr_payload' => ['required', 'string', 'max:255'],
            'trip_id' => ['nullable', 'uuid', 'exists:trips,id'],
        ]);

        [$ticketNumber, $ticketId] = $this->parseQrPayload($validated['qr_payload']);

        $ticket = Ticket::with(['trip.route', 'fromStation', 'toStation', 'finalDestinationStation', 'connection.trip', 'connection.transferStation', 'connection.destinationStation', 'boardedBy', 'compensations.replacementTrip'])
            ->where(function ($query) use ($ticketId, $ticketNumber) {
                if ($ticketId) {
                    $query->where('id', $ticketId);
                }

                if ($ticketNumber) {
                    $query->orWhere('ticket_number', $ticketNumber);
                }
            })
            ->first();

        if (! $ticket) {
            return response()->json([
                'valid' => false,
                'message' => 'Ticket introuvable.',
            ], 404);
        }

        $valid = $ticket->status === 'issued';
        $message = null;
        if (! empty($validated['trip_id'])) {
            $trip = Trip::findOrFail($validated['trip_id']);
            $this->assertCrewVehicleAccess($request, $trip);
            $connection = $ticket->connection;
            $compensation = $ticket->compensations->where('status', 'executed')->sortByDesc('executed_at')->first();
            $replacementApplies = $compensation?->compensation_type === 'free_rebooking' && $compensation->replacement_trip_id === $trip->id;
            $belongsToTrip = $ticket->trip_id === $trip->id
                || ($connection?->trip_id === $trip->id && in_array($connection->status, ['assigned', 'boarded', 'completed'], true))
                || $replacementApplies;
            $valid = $valid && $belongsToTrip;
            if ($compensation?->compensation_type === 'refund') {
                $valid = false;
                $message = 'Ce ticket a fait l’objet d’un remboursement exécuté.';
            } elseif (! $belongsToTrip) {
                $message = 'Ce ticket n’est pas affecté au voyage actuellement contrôlé.';
            } elseif (($connection?->trip_id === $trip->id && $connection->status !== 'assigned')
                || ($ticket->trip_id === $trip->id && $ticket->boarded_at)) {
                $valid = false;
                $message = 'Ce passager a déjà été embarqué sur ce voyage.';
            }
        }

        return response()->json([
            'valid' => $valid,
            'message' => $message,
            'ticket' => $this->ticketPayload($ticket, $validated['trip_id'] ?? null),
        ]);
    }

    public function tickets(Request $request, Trip $trip)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $tickets = Ticket::with(['fromStation', 'toStation', 'finalDestinationStation', 'connection', 'boardedBy', 'seller'])
            ->where('trip_id', $trip->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('seat_number')
            ->get();

        $connectionTickets = TicketConnection::with(['ticket.finalDestinationStation', 'transferStation', 'destinationStation', 'boardedBy'])
            ->where('trip_id', $trip->id)
            ->whereIn('status', ['assigned', 'boarded', 'completed'])
            ->get()
            ->map(function ($connection) {
                $ticket = $connection->ticket;
                $ticket->seat_number = $connection->seat_number;
                $ticket->boarded_at = $connection->boarded_at;
                $ticket->setRelation('fromStation', $connection->transferStation);
                $ticket->setRelation('toStation', $connection->destinationStation);
                $ticket->setRelation('connection', $connection);

                return $ticket;
            });

        $tickets = $tickets->concat($connectionTickets)->sortBy('seat_number')->values()
            ->map(fn (Ticket $ticket) => $this->ticketPayload($ticket, $trip->id));

        $offlineCache = $this->offlineTicketCachePayload($trip, $tickets->all());

        return response()->json([
            'tickets' => $tickets,
            'offline_cache' => $offlineCache,
        ]);
    }

    public function board(Request $request, Trip $trip, Ticket $ticket)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $validated = $request->validate([
            'boarded_at' => ['nullable', 'date'],
        ]);

        try {
            $ticket = app(BoardTicket::class)->execute(
                $request->user(),
                $trip,
                $ticket,
                isset($validated['boarded_at']) ? Carbon::parse($validated['boarded_at']) : null,
            );
        } catch (TicketingRuleViolation $exception) {
            return response()->json([
                'code' => $exception->reasonCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        return response()->json([
            'message' => 'Passager embarqué.',
            'ticket' => $this->ticketPayload($ticket, $trip->id),
        ]);
    }

    public function sell(Request $request, Trip $trip, TripSegmentService $segments, OptimisationService $optimisation)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $validated = $request->validate([
            'from_station_id' => ['required', 'uuid', 'exists:stations,id'],
            'to_station_id' => ['required', 'uuid', 'exists:stations,id'],
            'seat_number' => ['required', 'integer', 'min:1'],
            'passenger_name' => ['required', 'string', 'max:255'],
            'passenger_phone' => ['required', 'string', 'max:50'],
            'amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $salesDecision = app(TripSalesPolicy::class)->evaluate(
            $request->user(),
            $trip,
            $validated['from_station_id'],
            $validated['to_station_id'],
            'crew',
            [(int) $validated['seat_number']],
        );
        if (! $salesDecision->allowed) {
            return response()->json([
                'code' => $salesDecision->reasonCode,
                'message' => $salesDecision->message,
            ], 403);
        }

        [$validSegment, $segmentError, $stationIndices, $reqStartIndex, $reqEndIndex] = $segments->validateSegment(
            $trip,
            $validated['from_station_id'],
            $validated['to_station_id']
        );

        if (! $validSegment) {
            return response()->json(['message' => $segmentError], 422);
        }

        $price = $segments->fareAmount($validated['from_station_id'], $validated['to_station_id']);
        if ($price === null) {
            return response()->json(['message' => 'Aucun tarif actif trouvé pour ce segment.'], 422);
        }

        $requestedSeat = (int) $validated['seat_number'];

        try {
            DB::beginTransaction();

            Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();

            $occupiedSeats = $segments->overlappingSeatNumbers(
                TripSeatOccupancy::with('ticket')->where('trip_id', $trip->id)->get(),
                $stationIndices,
                $reqStartIndex,
                $reqEndIndex
            );

            if (in_array($requestedSeat, $occupiedSeats, true)) {
                $suggestion = collect($optimisation->getSuggestedSeats(
                    $trip->id,
                    $validated['to_station_id'],
                    1,
                    $validated['from_station_id']
                ))->firstWhere('seat_number', '!=', $requestedSeat);

                DB::rollBack();

                return response()->json([
                    'code' => 'seat_conflict',
                    'message' => 'Cette place a été vendue entre-temps.',
                    'requested_seat' => $requestedSeat,
                    'suggested_seat' => $suggestion ? (int) $suggestion['seat_number'] : null,
                ], 409);
            }

            if ($requestedSeat > $trip->vehicle->seat_count) {
                DB::rollBack();

                return response()->json(['message' => 'La place demandée n\'existe pas.'], 422);
            }

            $crewMember = $request->user();
            $ticket = Ticket::create([
                'ticket_number' => 'TKT-'.strtoupper(Str::random(8)),
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'seat_number' => $requestedSeat,
                'from_station_id' => $validated['from_station_id'],
                'to_station_id' => $validated['to_station_id'],
                'passenger_name' => $validated['passenger_name'],
                'passenger_phone' => $validated['passenger_phone'],
                'price' => $price,
                'seller_id' => null,
                'crew_member_id' => $crewMember->id,
                'station_id' => $validated['from_station_id'],
                'status' => 'issued',
                'qr_code' => 'QR-'.strtoupper(Str::random(12)),
            ]);

            $ticket->load(['fromStation', 'toStation']);
            $ticket->update([
                'qr_payload' => $ticket->qrPayloadData(),
            ]);

            TripSeatOccupancy::create([
                'trip_id' => $trip->id,
                'seat_number' => $requestedSeat,
                'ticket_id' => $ticket->id,
                'from_station_id' => $validated['from_station_id'],
                'to_station_id' => $validated['to_station_id'],
            ]);

            DB::commit();

            $seatMapEvent = new SeatMapUpdated(
                $trip->fresh([
                    'route.routeStopOrders.station',
                    'originStation',
                    'destinationStation',
                    'vehicle.vehicleType',
                ]),
                [[
                    'seat_number' => $requestedSeat,
                    'status' => 'occupied',
                    'ticket_id' => $ticket->id,
                    'to_station_id' => $ticket->to_station_id,
                ]],
                'ticket.created',
                $validated['from_station_id']
            );
            DB::afterCommit(fn () => event($seatMapEvent));

            return response()->json([
                'message' => 'Ticket vendu avec succès.',
                'ticket' => $this->ticketPayload($ticket->fresh(['fromStation', 'toStation', 'boardedBy']), $trip->id),
            ], 201);
        } catch (QueryException $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Conflit de réservation. Veuillez réessayer.',
            ], 409);
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    public function sync(Request $request, Trip $trip, TripSegmentService $segments, OptimisationService $optimisation)
    {
        $this->assertCrewVehicleAccess($request, $trip);

        $validated = $request->validate([
            'boardings' => ['sometimes', 'array'],
            'boardings.*.client_action_id' => ['required', 'uuid'],
            'boardings.*.ticket_id' => ['required', 'uuid', 'exists:tickets,id'],
            'boardings.*.boarded_at' => ['nullable', 'date'],
            'sales' => ['sometimes', 'array'],
            'sales.*.client_action_id' => ['required', 'uuid'],
            'sales.*.from_station_id' => ['required', 'uuid', 'exists:stations,id'],
            'sales.*.to_station_id' => ['required', 'uuid', 'exists:stations,id'],
            'sales.*.seat_number' => ['required', 'integer', 'min:1'],
            'sales.*.passenger_name' => ['required', 'string', 'max:255'],
            'sales.*.passenger_phone' => ['required', 'string', 'max:50'],
        ]);

        $results = [
            'boarded' => [],
            'sold' => [],
            'failed' => [],
        ];

        foreach ($validated['boardings'] ?? [] as $boarding) {
            try {
                $outcome = $this->processOfflineAction(
                    $request,
                    $trip,
                    $boarding['client_action_id'],
                    'boarding',
                    $boarding,
                    function () use ($request, $trip, $boarding): array {
                        $ticket = Ticket::with('connection')->find($boarding['ticket_id']);
                        if (! $ticket) {
                            return ['ok' => false, 'result' => [
                                'client_action_id' => $boarding['client_action_id'],
                                'type' => 'boarding',
                                'ticket_id' => $boarding['ticket_id'],
                                'code' => 'ticket_not_found',
                                'message' => 'Ticket introuvable.',
                            ]];
                        }

                        try {
                            $ticket = app(BoardTicket::class)->execute(
                                $request->user(),
                                $trip,
                                $ticket,
                                isset($boarding['boarded_at']) ? Carbon::parse($boarding['boarded_at']) : null,
                            );
                        } catch (TicketingRuleViolation $exception) {
                            return ['ok' => false, 'result' => [
                                'client_action_id' => $boarding['client_action_id'],
                                'type' => 'boarding',
                                'ticket_id' => $boarding['ticket_id'],
                                'code' => $exception->reasonCode,
                                'message' => $exception->getMessage(),
                            ]];
                        }

                        return ['ok' => true, 'result' => ['client_action_id' => $boarding['client_action_id']] + $this->ticketPayload($ticket, $trip->id),
                        ];
                    },
                );
                $results[$outcome['ok'] ? 'boarded' : 'failed'][] = $outcome['result'];
            } catch (\Throwable $exception) {
                $results['failed'][] = [
                    'client_action_id' => $boarding['client_action_id'],
                    'type' => 'boarding',
                    'ticket_id' => $boarding['ticket_id'],
                    'code' => 'temporary_sync_error',
                    'message' => 'Action non enregistrée, une nouvelle tentative est possible.',
                ];
            }
        }

        foreach ($validated['sales'] ?? [] as $sale) {
            try {
                $outcome = $this->processOfflineAction(
                    $request,
                    $trip,
                    $sale['client_action_id'],
                    'sale',
                    $sale,
                    function () use ($request, $trip, $sale, $segments, $optimisation): array {
                        $saleRequest = new Request($sale);
                        $saleRequest->setUserResolver(fn () => $request->user());
                        $saleRequest->setRouteResolver(fn () => $request->route());
                        $response = $this->sell($saleRequest, $trip, $segments, $optimisation);
                        $payload = $response->getData(true);

                        if (($response->getStatusCode() ?? 200) >= 400) {
                            return ['ok' => false, 'result' => [
                                'client_action_id' => $sale['client_action_id'],
                                'type' => 'sale',
                                'payload' => $sale,
                                'http_status' => $response->getStatusCode(),
                                'code' => $payload['code'] ?? 'business_error',
                                'message' => $payload['message'] ?? 'Échec de synchronisation.',
                                'suggested_seat' => $payload['suggested_seat'] ?? null,
                            ]];
                        }

                        return ['ok' => true, 'result' => ['client_action_id' => $sale['client_action_id']] + $payload['ticket'],
                        ];
                    },
                );
                $results[$outcome['ok'] ? 'sold' : 'failed'][] = $outcome['result'];
            } catch (\Throwable $exception) {
                $results['failed'][] = [
                    'client_action_id' => $sale['client_action_id'],
                    'type' => 'sale',
                    'payload' => $sale,
                    'code' => 'temporary_sync_error',
                    'message' => 'Action non enregistrée, une nouvelle tentative est possible.',
                ];
            }
        }

        return response()->json([
            'message' => 'Synchronisation terminée.',
            'results' => $results,
        ]);
    }

    private function processOfflineAction(Request $request, Trip $trip, string $id, string $type, array $payload, Closure $callback): array
    {
        return DB::transaction(function () use ($request, $trip, $id, $type, $payload, $callback): array {
            $payloadHash = hash('sha256', json_encode($this->canonicalPayload($payload), JSON_THROW_ON_ERROR));
            $inserted = DB::table('crew_offline_actions')->insertOrIgnore([
                'id' => $id,
                'crew_member_id' => $request->user()->id,
                'trip_id' => $trip->id,
                'type' => $type,
                'status' => 'processing',
                'payload_hash' => $payloadHash,
                'request_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'result' => json_encode(['state' => 'processing'], JSON_THROW_ON_ERROR),
                'attempt_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                $record = DB::table('crew_offline_actions')
                    ->where('id', $id)
                    ->where('crew_member_id', $request->user()->id)
                    ->where('trip_id', $trip->id)
                    ->where('type', $type)
                    ->lockForUpdate()
                    ->first();
                if (! $record) {
                    throw new \RuntimeException('Identifiant d’action hors ligne déjà utilisé dans un autre contexte.');
                }
                if ($record->payload_hash && ! hash_equals($record->payload_hash, $payloadHash)) {
                    return ['ok' => false, 'result' => [
                        'client_action_id' => $id,
                        'type' => $type,
                        'code' => 'client_action_id_reused',
                        'message' => 'Cet identifiant d’action correspond déjà à un autre contenu.',
                    ]];
                }

                $stored = json_decode($record->result, true, flags: JSON_THROW_ON_ERROR);
                if (array_key_exists('ok', $stored) && array_key_exists('result', $stored)) {
                    return $stored;
                }

                throw new \RuntimeException('Une action hors ligne incomplète doit être rejouée.');
            }

            $outcome = $callback();
            $errorCode = $outcome['ok'] ? null : ($outcome['result']['code'] ?? 'business_error');
            $status = $outcome['ok']
                ? 'confirmed'
                : ($errorCode === 'seat_conflict' ? 'conflict' : 'rejected');
            $retentionDays = $outcome['ok']
                ? (int) config('transport.offline.confirmed_retention_days', 7)
                : (int) config('transport.offline.rejected_retention_days', 30);
            DB::table('crew_offline_actions')->where('id', $id)->update([
                'status' => $status,
                'result' => json_encode($outcome, JSON_THROW_ON_ERROR),
                'error_code' => $errorCode,
                'processed_at' => now(),
                'expires_at' => now()->addDays($retentionDays),
                'updated_at' => now(),
            ]);

            return $outcome;
        }, 3);
    }

    private function canonicalPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalPayload($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return $payload;
    }

    private function parseQrPayload(string $payload): array
    {
        $parts = array_values(array_filter(explode('|', trim($payload))));

        if (count($parts) >= 3 && $parts[0] === 'TIKETI') {
            return [$parts[1] ?? null, $parts[2] ?? null];
        }

        return [$payload, null];
    }

    private function ticketPayload(Ticket $ticket, ?string $contextTripId = null): array
    {
        $connection = $ticket->connection;
        $compensation = $ticket->compensations()->where('status', 'executed')->latest('executed_at')->first();
        $replacementApplies = $compensation?->compensation_type === 'free_rebooking'
            && $compensation->replacement_trip_id === $contextTripId;
        $connectionBoarding = $connection && $contextTripId
            && $connection->trip_id === $contextTripId
            && $ticket->trip_id !== $contextTripId;

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'trip_id' => $ticket->trip_id,
            'seat_number' => $replacementApplies ? $compensation->replacement_seat_number : ($connectionBoarding ? $connection->seat_number : $ticket->seat_number),
            'price' => $ticket->price,
            'passenger_name' => $ticket->passenger_name,
            'passenger_phone' => $ticket->passenger_phone,
            'status' => $ticket->status,
            'boarded_at' => ($connectionBoarding ? $connection->boarded_at : $ticket->boarded_at)?->toIso8601String(),
            'from_station' => ($connectionBoarding ? $connection->transferStation : $ticket->fromStation) ? [
                'id' => ($connectionBoarding ? $connection->transferStation : $ticket->fromStation)->id,
                'name' => ($connectionBoarding ? $connection->transferStation : $ticket->fromStation)->name,
            ] : null,
            'to_station' => ($connectionBoarding ? $connection->destinationStation : $ticket->toStation) ? [
                'id' => ($connectionBoarding ? $connection->destinationStation : $ticket->toStation)->id,
                'name' => ($connectionBoarding ? $connection->destinationStation : $ticket->toStation)->name,
            ] : null,
            'final_destination' => $ticket->finalDestinationStation ? [
                'id' => $ticket->finalDestinationStation->id,
                'name' => $ticket->finalDestinationStation->name,
            ] : null,
            'connection' => $ticket->connection ? [
                'status' => $ticket->connection->status,
                'trip_id' => $ticket->connection->trip_id,
                'seat_number' => $ticket->connection->seat_number,
                'transfer_station_id' => $ticket->connection->transfer_station_id,
                'destination_station_id' => $ticket->connection->destination_station_id,
                'transfer_station' => $ticket->connection->transferStation ? [
                    'id' => $ticket->connection->transferStation->id,
                    'name' => $ticket->connection->transferStation->name,
                ] : null,
                'destination_station' => $ticket->connection->destinationStation ? [
                    'id' => $ticket->connection->destinationStation->id,
                    'name' => $ticket->connection->destinationStation->name,
                ] : null,
                'has_conflict' => $ticket->connection->hasConflict(),
            ] : null,
            'is_connection_segment' => $connectionBoarding,
            'compensation' => $compensation ? [
                'reference' => $compensation->reference,
                'type' => $compensation->compensation_type,
                'status' => $compensation->status,
                'amount' => $compensation->amount,
                'reason' => $compensation->reason,
                'replacement_trip_id' => $compensation->replacement_trip_id,
                'replacement_seat_number' => $compensation->replacement_seat_number,
            ] : null,
            'boarded_by' => ($connectionBoarding ? $connection?->boardedBy : $ticket->boardedBy) ? [
                'id' => ($connectionBoarding ? $connection->boardedBy : $ticket->boardedBy)->id,
                'name' => ($connectionBoarding ? $connection->boardedBy : $ticket->boardedBy)->name,
            ] : null,
        ];
    }

    private function offlineTicketCachePayload(Trip $trip, array $tickets): array
    {
        $generatedAt = now();
        $payload = [
            'schema_version' => 1,
            'trip_id' => (string) $trip->id,
            'generated_at' => $generatedAt->toIso8601String(),
            'expires_at' => $generatedAt->copy()
                ->addMinutes((int) config('transport.offline.ticket_cache_ttl_minutes', 360))
                ->toIso8601String(),
            'tickets' => collect($tickets)->map(fn (array $ticket) => [
                'id' => (string) $ticket['id'],
                'ticket_number' => (string) $ticket['ticket_number'],
                'trip_id' => (string) $trip->id,
                'from_station_id' => $ticket['from_station']['id'] ?? null,
                'to_station_id' => $ticket['to_station']['id'] ?? null,
                'seat_number' => $ticket['seat_number'] !== null ? (int) $ticket['seat_number'] : null,
                'status' => (string) $ticket['status'],
                'boarded_at' => $ticket['boarded_at'],
                'segment_type' => ! empty($ticket['is_connection_segment']) ? 'connection' : 'primary',
            ])->values()->all(),
        ];
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signingKey = (string) (config('transport.offline.cache_signing_key') ?: config('app.key'));

        return [
            ...$payload,
            'payload_hash' => hash('sha256', $encodedPayload),
            'signature' => hash_hmac('sha256', $encodedPayload, $signingKey),
        ];
    }

    private function assertCrewVehicleAccess(Request $request, Trip $trip): void
    {
        abort_unless(
            app(CrewTripAccessPolicy::class)->canAccess($request->user(), $trip),
            403,
            'Ce voyage ne correspond pas à vos affectations.',
        );
    }
}
