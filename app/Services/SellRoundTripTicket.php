<?php

namespace App\Services;

use App\Domain\Ticketing\TicketingRuleViolation;
use App\Models\DepartureSchedule;
use App\Models\OkohiTicketOutbox;
use App\Models\Ticket;
use App\Models\TicketJourney;
use App\Models\Trip;
use App\Models\TripSeatOccupancy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vente d'un billet aller-retour : création atomique du billet, du droit
 * aller et du droit retour dans une transaction SQL. Le prix est figé au
 * moment de la vente (jamais le montant fourni par le client web).
 *
 * Modes de retour :
 * - fixed_schedule : programme + date (+ heure) → droit en attente de la
 *   matérialisation nocturne (quota garanti déduit) ;
 * - date_flexible : date seule → pool de retours de la date ;
 * - open : ni date ni heure → pool général.
 *
 * Quand le car réel est inconnu et que la politique le permet, la vente est
 * quantity_only : billet valide, siège non attribué, capacité consommée.
 */
final class SellRoundTripTicket
{
    public function __construct(
        private readonly RoundTripFareCalculator $calculator,
        private readonly TripCapacityService $capacity,
        private readonly ReturnQuotaService $quota,
    ) {}

    /**
     * Vente aller-retour d'un passager.
     *
     * @param  array{
     *     trip: Trip,
     *     from_station_id: string,
     *     to_station_id: string,
     *     journey_type: string,
     *     seat_number: int|null,
     *     return_mode: string|null,
     *     return_schedule_id: string|null,
     *     return_date: string|null,
     *     return_time: string|null,
     *     passenger_name: string|null,
     *     passenger_phone: string|null,
     *     seller_id: string,
     *     station_id: string,
     *     final_destination_station_id: string|null,
     *     transfer_station_id: string|null,
     *     fare_calculation: array|null,
     *     okohi_customer_number: string|null,
     *     okohi_reward_id: string|null,
     *     okohi_transaction_id: string|null,
     * }  $sale
     *
     * @return array{ticket: Ticket, outbound: TicketJourney, return: TicketJourney|null}
     */
    public function sell(array $sale): array
    {
        $trip = $sale['trip'];
        $isRoundTrip = ($sale['journey_type'] ?? 'one_way') === Ticket::JOURNEY_TYPE_ROUND_TRIP;

        $fare = $this->calculator->calculate(
            $sale['from_station_id'],
            $sale['to_station_id']
        );

        // Montant encaissé : forfait si l'aller-retour est retenu, sinon total normal.
        $amountToCollect = $isRoundTrip
            ? $fare['amount_to_collect']
            : $fare['outbound_amount'];

        $discount = $isRoundTrip ? $fare['discount'] : 0;
        $normalTotal = $isRoundTrip ? $fare['normal_total'] : $fare['outbound_amount'];

        // Vérifications du mode de retour choisi.
        $returnJourney = null;
        if ($isRoundTrip) {
            $returnMode = $sale['return_mode'] ?? null;

            if (! $returnMode || ! $this->calculator->validateSelectionMode($fare, $returnMode)) {
                throw new TicketingRuleViolation(
                    'invalid_return_mode',
                    'Ce mode de retour n’est pas autorisé pour cette offre.'
                );
            }

            if ($returnMode === TicketJourney::SELECTION_FIXED_SCHEDULE) {
                if (empty($sale['return_schedule_id']) || empty($sale['return_date'])) {
                    throw new TicketingRuleViolation(
                        'return_schedule_required',
                        'Un retour à créneau précis exige un programme de départ et une date.'
                    );
                }

                // Validation serveur complète (point E) : actif, trajet inverse,
                // jour de circulation, exceptions, heure calculée, quota.
                $validatedReturn = app(ValidateFixedScheduleReturn::class)->validate(
                    $sale['return_schedule_id'],
                    $sale['return_date'],
                    $sale['from_station_id'],
                    $sale['to_station_id'],
                );
                $schedule = $validatedReturn['schedule'];
                $sale['return_time'] = $validatedReturn['departure_time'];

                // La date est normalisée (date de service du programme).
                $sale['return_date'] = $validatedReturn['service_date'];
            }

            if ($returnMode === TicketJourney::SELECTION_DATE_FLEXIBLE && empty($sale['return_date'])) {
                throw new TicketingRuleViolation(
                    'return_date_required',
                    'Un retour à date seule exige une date.'
                );
            }
        }

        $returnValidUntil = $isRoundTrip
            ? now()->addDays($fare['default_validity_days'])->endOfDay()
            : null;

        return DB::transaction(function () use ($sale, $trip, $isRoundTrip, $fare, $amountToCollect, $discount, $normalTotal, $returnValidUntil) {
            // Sérialise la vente au niveau du voyage (dernière place).
            Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            // Réserve une unité de capacité (siège confirmé OU quantité).
            $this->capacity->reserveUnits($trip, 1, $sale['from_station_id'], $sale['to_station_id']);

            $returnJourney = null;

            // Réserve le quota du retour garanti, si applicable.
            if ($isRoundTrip && $sale['return_mode'] === TicketJourney::SELECTION_FIXED_SCHEDULE) {
                $schedule = DepartureSchedule::find($sale['return_schedule_id']);
                if ($schedule) {
                    $this->quota->reserve($schedule, $sale['return_date'], 1);
                }
            }

            $ticket = Ticket::create([
                'ticket_number' => 'TKT-'.strtoupper(Str::random(8)),
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'from_station_id' => $sale['from_station_id'],
                'to_station_id' => $sale['to_station_id'],
                'final_destination_station_id' => $sale['final_destination_station_id'] ?? null,
                'transfer_station_id' => $sale['transfer_station_id'] ?? null,
                'seat_number' => $sale['seat_number'],
                'passenger_name' => $sale['passenger_name'] ?? 'Passager',
                'passenger_phone' => $sale['passenger_phone'] ?? '',
                'price' => $amountToCollect,
                'seller_id' => $sale['seller_id'],
                'station_id' => $sale['station_id'],
                'status' => 'issued',
                'boarding_group' => $sale['seat_number'] !== null && $trip->vehicle
                    ? app(OptimisationService::class)->computeBoardingGroup($trip->vehicle->vehicleType, $sale['seat_number'])
                    : null,
                'qr_code' => 'QR-'.strtoupper(Str::random(12)),
                'payment_method' => 'cash',
                'gross_amount' => $normalTotal,
                'discount_amount' => $discount,
                'amount_collected' => $amountToCollect,
                'journey_type' => $sale['journey_type'],
                'normal_total_amount' => $normalTotal,
                'round_trip_discount_amount' => $discount,
                'return_valid_until' => $returnValidUntil,
                'okohi_delivery_status' => 'not_requested',
                'okohi_customer_number' => $sale['okohi_customer_number'] ?? null,
                'okohi_reward_id' => $sale['okohi_reward_id'] ?? null,
                'okohi_transaction_id' => $sale['okohi_transaction_id'] ?? null,
                'settings' => [
                    'fare_calculation' => $sale['fare_calculation'] ?? [
                        'type' => $isRoundTrip ? 'round_trip' : 'direct',
                        'amount' => $amountToCollect,
                        'normal_total' => $normalTotal,
                        'discount' => $discount,
                    ],
                    'sold_without_vehicle' => $sale['seat_number'] === null,
                ],
            ]);

            // Droit aller.
            $outbound = TicketJourney::create([
                'ticket_id' => $ticket->id,
                'direction' => TicketJourney::DIRECTION_OUTBOUND,
                'from_station_id' => $sale['from_station_id'],
                'to_station_id' => $sale['to_station_id'],
                'selection_mode' => TicketJourney::SELECTION_FIXED_TRIP,
                'trip_id' => $trip->id,
                'vehicle_id' => $trip->vehicle_id,
                'seat_number' => $sale['seat_number'],
                'seat_assignment_status' => $sale['seat_number'] !== null
                    ? TicketJourney::SEAT_CONFIRMED
                    : TicketJourney::SEAT_UNASSIGNED,
                'status' => $sale['seat_number'] !== null
                    ? TicketJourney::STATUS_ASSIGNED
                    : TicketJourney::STATUS_AWAITING_TRIP,
                'valid_from' => now(),
            ]);

            // Droit retour.
            if ($isRoundTrip) {
                $returnJourney = $this->createReturnJourney($ticket, $sale);
            }

            // Occupation physique uniquement si le siège est confirmé.
            if ($sale['seat_number'] !== null) {
                TripSeatOccupancy::create([
                    'trip_id' => $trip->id,
                    'seat_number' => $sale['seat_number'],
                    'ticket_id' => $ticket->id,
                    'from_station_id' => $sale['from_station_id'],
                    'to_station_id' => $sale['to_station_id'],
                ]);
            }

            // Publication Okohi en file (jamais bloquante pour la vente).
            app(OkohiTicketPublisher::class)->enqueue($ticket, OkohiTicketOutbox::OPERATION_CREATE);

            return [
                'ticket' => $ticket,
                'outbound' => $outbound,
                'return' => $returnJourney,
            ];
        });
    }

    private function createReturnJourney(Ticket $ticket, array $sale): TicketJourney
    {
        $mode = $sale['return_mode'];

        $status = match ($mode) {
            TicketJourney::SELECTION_FIXED_SCHEDULE => TicketJourney::STATUS_AWAITING_TRIP,
            TicketJourney::SELECTION_DATE_FLEXIBLE => TicketJourney::STATUS_READY,
            default => TicketJourney::STATUS_PENDING,
        };

        // Validité du retour : celle de l'offre, sinon 30 jours par défaut.
        $fare = $this->calculator->calculate($sale['from_station_id'], $sale['to_station_id']);
        $validityDays = $fare['default_validity_days'];

        return TicketJourney::create([
            'ticket_id' => $ticket->id,
            'direction' => TicketJourney::DIRECTION_RETURN,
            'from_station_id' => $sale['to_station_id'], // trajet inverse
            'to_station_id' => $sale['from_station_id'],
            'selection_mode' => $mode,
            'departure_schedule_id' => $mode === TicketJourney::SELECTION_FIXED_SCHEDULE
                ? ($sale['return_schedule_id'] ?? null)
                : null,
            'desired_travel_date' => in_array($mode, [
                TicketJourney::SELECTION_FIXED_SCHEDULE,
                TicketJourney::SELECTION_DATE_FLEXIBLE,
            ], true) ? ($sale['return_date'] ?? null) : null,
            'desired_departure_time' => $mode === TicketJourney::SELECTION_FIXED_SCHEDULE
                ? ($sale['return_time'] ?? null)
                : null,
            'seat_number' => null,
            'seat_assignment_status' => TicketJourney::SEAT_UNASSIGNED,
            'status' => $status,
            'valid_from' => now(),
            'valid_until' => now()->addDays($validityDays)->endOfDay(),
        ]);
    }
}
