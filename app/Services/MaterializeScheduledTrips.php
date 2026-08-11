<?php

namespace App\Services;

use App\Models\DepartureSchedule;
use App\Models\OperationalSetting;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crée chaque nuit les occurrences manquantes des programmes de départ dans la
 * fenêtre opérationnelle (journée + lookahead configuré), avec un véhicule
 * technique de planification, sales_ready = false et operational_ready = false.
 *
 * Idempotent : la contrainte unique (departure_schedule_id, service_date)
 * garantit qu'une seconde exécution ne crée jamais de doublon. Une occurrence
 * en échec est rapportée sans interrompre les autres.
 */
final class MaterializeScheduledTrips
{
    public function __construct(
        private readonly DepartureScheduleCalendar $calendar,
        private readonly ResolvePlanningVehicle $planningVehicle,
    ) {}

    /**
     * Matérialise les occurrences manquantes pour la fenêtre opérationnelle.
     *
     * @return array{created: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function materialize(?CarbonImmutable $instant = null): array
    {
        $instant ??= CarbonImmutable::now();

        // Point 8 : si le tenant n'a pas activé les programmes de départ,
        // l'ancien mécanisme récurrent (trips:replicate) reste en vigueur —
        // la matérialisation nocturne ne crée rien. On utilise le VRAI
        // contexte tenancy (tenancy()->initialized + tenant()), pas un
        // binding artificiel du conteneur.
        if (function_exists('tenancy') && tenancy()->initialized && ! tenant()?->departureProgramsEnabled()) {
            return [
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => ['feature_flag_disabled' => 'departure_programs désactivé pour ce tenant : matérialisation ignorée.'],
            ];
        }

        [$windowStart, $windowEnd] = $this->operationalWindow($instant);

        $schedules = DepartureSchedule::with('exceptions')
            ->where('active', true)
            ->get();

        $report = [
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($schedules as $schedule) {
            $occurrences = $this->collectOccurrences($schedule, $windowStart, $windowEnd);

            foreach ($occurrences as $occurrence) {
                $result = $this->createOccurrence($schedule, $occurrence);

                $report[$result === 'created' ? 'created' : ($result === 'skipped' ? 'skipped' : 'failed')]++;

                if ($result === 'failed') {
                    $report['errors'][] = "Programme {$schedule->id} · date {$occurrence['service_date']}";
                }
            }
        }

        if ($report['failed'] > 0) {
            Log::warning('trips:materialize-schedules — échecs partiels', [
                'window' => [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()],
                'report' => $report,
            ]);
        }

        return $report;
    }

    /**
     * Fenêtre opérationnelle alignée sur CrewTripVisibility (même source).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function operationalWindow(CarbonImmutable $instant): array
    {
        $settings = OperationalSetting::current();
        $startHour = $settings->operationalDayStartHour();
        $start = $instant->startOfDay()->addHours($startHour);
        if ($instant->lt($start)) {
            $start = $start->subDay();
        }
        $end = $start->addHours($settings->scheduledTripLookaheadHours());

        return [$start, $end];
    }

    /**
     * Liste des dates de service du programme dans la fenêtre.
     *
     * @return Collection<int, array{service_date: string, departure_time: CarbonImmutable, capacity: int|null}>
     */
    private function collectOccurrences(DepartureSchedule $schedule, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): Collection
    {
        $occurrences = collect();
        $day = $windowStart->copy()->startOfDay();

        // Borne max d'itérations pour éviter les boucles infinies (fenêtre bornée par le lookahead ≤ 168 h).
        $guard = 0;
        while ($day->lt($windowEnd) && $guard < 400) {
            $guard++;

            $result = $this->calendar->occurrencesForDate($schedule, $day);

            if ($result->isNotEmpty()) {
                // Point 4 : les occurrences indisponibles (hors programme,
                // annulées, suspendues) sont désormais VISIBLES avec
                // cancelled=true — le cron ne doit matérialiser que les
                // occurrences réellement exploitables.
                $occurrences = $occurrences->concat($result->filter(fn ($occ) => ! ($occ['cancelled'] ?? false)));
            }

            $day = $day->addDay();
        }

        return $occurrences;
    }

    /**
     * Crée un voyage pour une occurrence si absent (idempotent).
     *
     * @param  array{service_date: string, departure_time: CarbonImmutable, capacity: int|null}  $occurrence
     * @return string created|skipped|failed
     */
    private function createOccurrence(DepartureSchedule $schedule, array $occurrence): string
    {
        $serviceDate = $occurrence['service_date'];

        $exists = Trip::where('departure_schedule_id', $schedule->id)
            ->whereDate('service_date', $serviceDate)
            ->exists();

        if ($exists) {
            return 'skipped';
        }

        try {
            $timezone = $schedule->timezone ?: config('app.timezone', 'UTC');
            // L'heure locale du programme est convertie en UTC pour le stockage :
            // le reste de la plateforme lit departure_at en UTC (config app.timezone).
            $departureAt = CarbonImmutable::parse(
                $serviceDate.' '.$occurrence['departure_time']->format('H:i'),
                $timezone
            )->setTimezone('UTC');

            $vehicle = $this->planningVehicle->resolve($schedule->default_vehicle_type_id);

            // La politique résolue est copiée sur le voyage pour préserver l'historique.
            $policy = $schedule->resolvedPolicy(
                (string) data_get(OperationalSetting::current()->settings, 'default_vehicle_assignment_policy', DepartureSchedule::POLICY_REQUIRE_REAL_VEHICLE)
            );

            DB::transaction(function () use ($schedule, $occurrence, $departureAt, $vehicle, $policy, $serviceDate) {
                $trip = Trip::create([
                    'departure_schedule_id' => $schedule->id,
                    'service_date' => $serviceDate,
                    'route_id' => $schedule->route_id,
                    'origin_station_id' => $schedule->origin_station_id,
                    'destination_station_id' => $schedule->destination_station_id,
                    'vehicle_id' => $vehicle->id,
                    'departure_at' => $departureAt,
                    'status' => 'scheduled',
                    'booking_type' => $schedule->booking_type ?: 'seat_assignment',
                    'sales_control' => $schedule->sales_control ?: 'open',
                    'allows_open_connections' => (bool) $schedule->allows_open_connections,
                    'automatic_connection_allocation' => (bool) $schedule->automatic_connection_allocation,
                    'planned_capacity_snapshot' => $occurrence['capacity'],
                    'vehicle_assignment_policy' => $policy,
                    'sales_ready' => false,
                    'operational_ready' => false,
                    'is_replicable' => false,
                    'settings' => array_merge($schedule->settings ?? [], [
                        'materialized_from_schedule' => true,
                        'materialized_at' => now()->toDateTimeString(),
                    ]),
                ]);

                // Rattache les retours fixed_schedule qui ont choisi exactement
                // ce programme et cette date (aucune place définitive tant que
                // le car réel n'est pas affecté).
                app(ReturnJourneyAllocator::class)->attachScheduleReturns($trip);
            });

            return 'created';
        } catch (Throwable $e) {
            Log::error('trips:materialize-schedules — création échouée', [
                'schedule_id' => $schedule->id,
                'service_date' => $serviceDate,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }
}
