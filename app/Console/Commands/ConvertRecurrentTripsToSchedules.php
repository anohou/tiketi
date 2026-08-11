<?php

namespace App\Console\Commands;

use App\Models\DepartureSchedule;
use App\Models\Tenant;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Conversion assistée des anciens voyages récurrents (is_replicable = true)
 * en programmes de départ (point M / §12 Étape B).
 *
 * Pour chaque combinaison unique route/origine/destination/heure, propose un
 * programme. Les jours de circulation ne sont PAS devinés : sans indication,
 * le programme est créé du lundi au dimanche et l'administrateur ajuste.
 * Ne crée jamais de doublon : idempotent.
 */
class ConvertRecurrentTripsToSchedules extends Command
{
    protected $signature = 'trips:convert-recurrent-schedules {--tenant= : Traiter un seul tenant}';

    protected $description = 'Convertit les anciens voyages récurrents en programmes de départ (assisté, idempotent).';

    public function handle(): int
    {
        $tenantOption = $this->option('tenant');

        $tenants = $tenantOption
            ? Tenant::where('id', $tenantOption)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error('Aucun tenant trouvé.');

            return self::FAILURE;
        }

        $totalProposed = 0;
        $totalSkipped = 0;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            $recurrent = Trip::with(['route', 'originStation', 'destinationStation'])
                ->where('is_replicable', true)
                ->whereNotNull('origin_station_id')
                ->whereNotNull('destination_station_id')
                ->get();

            // Combinaison unique : route + origine + destination + heure.
            $grouped = $recurrent->groupBy(function (Trip $trip) {
                return implode('|', [
                    $trip->route_id,
                    $trip->origin_station_id,
                    $trip->destination_station_id,
                    $trip->departure_at?->format('H:i'),
                ]);
            });

            $proposed = 0;
            $skipped = 0;

            foreach ($grouped as $key => $trips) {
                $first = $trips->first();

                if (! $first->departure_at) {
                    $skipped++;

                    continue;
                }

                $exists = DepartureSchedule::where('route_id', $first->route_id)
                    ->where('origin_station_id', $first->origin_station_id)
                    ->where('destination_station_id', $first->destination_station_id)
                    ->whereTime('departure_time', $first->departure_at->format('H:i'))
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $vehicleType = $first->vehicle?->vehicleType;

                // Un programme actif exige un type de véhicule prévisionnel
                // (§3.1) : sans type connu, on NE DEVINE PAS — on saute et on
                // le signale pour décision fonctionnelle.
                if (! $vehicleType) {
                    $this->warn("Tenant {$tenant->id} : voyage {$first->id} sans type de véhicule — programme NON créé. Affectez un type de véhicule puis relancez.");

                    $skipped++;

                    continue;
                }

                DB::transaction(function () use ($first, $vehicleType, $trips) {
                    DepartureSchedule::create([
                        'station_id' => $first->origin_station_id,
                        'route_id' => $first->route_id,
                        'origin_station_id' => $first->origin_station_id,
                        'destination_station_id' => $first->destination_station_id,
                        'departure_time' => $first->departure_at->format('H:i'),
                        // Jours NON devinés : tout ouvert, l'administrateur ajuste.
                        'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                        'valid_from' => CarbonImmutable::now()->startOfDay()->toDateString(),
                        'timezone' => 'UTC',
                        'planned_capacity' => $first->capacity(),
                        'default_vehicle_type_id' => $vehicleType?->id,
                        'vehicle_assignment_policy' => DepartureSchedule::POLICY_REQUIRE_REAL_VEHICLE,
                        'active' => true,
                        'created_by' => $first->creator_id,
                        'settings' => [
                            'converted_from_recurrence' => true,
                            'converted_at' => now()->toIso8601String(),
                            'source_trip_ids' => $trips->pluck('id')->all(),
                        ],
                    ]);
                });

                $proposed++;
            }

            $this->info("Tenant {$tenant->id} : {$proposed} programme(s) proposé(s), {$skipped} ignoré(s) (déjà existant ou sans horaire).");
            $totalProposed += $proposed;
            $totalSkipped += $skipped;

            tenancy()->end();
        }

        $this->info("Total : {$totalProposed} programme(s) proposé(s), {$totalSkipped} ignoré(s).");
        $this->warn('Rappel : validez les jours de circulation et la capacité dans l’administration avant d’activer la matérialisation.');

        return self::SUCCESS;
    }
}
