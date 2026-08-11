<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\MaterializeScheduledTrips;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MaterializeScheduledTripsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trips:materialize-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Matérialise les voyages manquants des programmes de départ dans la fenêtre opérationnelle, avec un véhicule technique de planification.';

    /**
     * Execute the console command.
     */
    public function handle(MaterializeScheduledTrips $service): int
    {
        $failed = false;
        $totalCreated = 0;

        if (class_exists(Tenant::class) && Schema::hasTable('tenants') && Tenant::count() > 0) {
            $tenants = Tenant::all();

            foreach ($tenants as $tenant) {
                $this->info("Matérialisation des départs pour le tenant : {$tenant->id}");

                tenancy()->initialize($tenant);

                try {
                    $report = $service->materialize();
                    $totalCreated += $report['created'];
                    $this->line("  créés : {$report['created']} · ignorés : {$report['skipped']} · échecs : {$report['failed']}");

                    foreach ($report['errors'] as $error) {
                        $this->error("  Échec : {$error}");
                    }

                    if ($report['failed'] > 0) {
                        $failed = true;
                    }
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("Échec de la matérialisation pour le tenant {$tenant->id} : ".$e->getMessage());
                } finally {
                    tenancy()->end();
                }
            }
        } else {
            $this->info('Matérialisation en contexte local (single-tenant).');

            try {
                $report = $service->materialize();
                $totalCreated += $report['created'];
                $this->line("  créés : {$report['created']} · ignorés : {$report['skipped']} · échecs : {$report['failed']}");

                foreach ($report['errors'] as $error) {
                    $this->error("  Échec : {$error}");
                }

                if ($report['failed'] > 0) {
                    $failed = true;
                }
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('Échec de la matérialisation : '.$e->getMessage());
            }
        }

        if ($failed) {
            $this->error('Matérialisation terminée avec des erreurs.');

            return self::FAILURE;
        }

        $this->info("Matérialisation terminée avec succès ({$totalCreated} voyage(s) créé(s)).");

        return self::SUCCESS;
    }
}
