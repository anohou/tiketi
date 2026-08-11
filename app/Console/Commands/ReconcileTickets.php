<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TicketReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileTickets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rapproche tickets, droits de voyage, occupations de sièges et chiffre d’affaires.';

    /**
     * Execute the console command.
     */
    public function handle(TicketReconciliationService $service): int
    {
        $failed = false;

        if (class_exists(Tenant::class) && Schema::hasTable('tenants') && Tenant::count() > 0) {
            foreach (Tenant::all() as $tenant) {
                $this->info("Rapprochement pour le tenant : {$tenant->id}");

                tenancy()->initialize($tenant);

                try {
                    $this->printReport($service->reconcile());
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("Échec du rapprochement pour le tenant {$tenant->id} : ".$e->getMessage());
                } finally {
                    tenancy()->end();
                }
            }
        } else {
            $this->info('Rapprochement en contexte local (single-tenant).');

            try {
                $this->printReport($service->reconcile());
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('Échec du rapprochement : '.$e->getMessage());
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function printReport(array $report): void
    {
        $this->line("  billets : {$report['tickets_total']} (dont {$report['tickets_issued']} émis)");
        $this->line("  droits : {$report['journeys_total']} (aller {$report['journeys_outbound']} · retour {$report['journeys_return']})");
        $this->line("  billets sans droit aller : {$report['tickets_without_outbound']}");
        $this->line("  occupations : {$report['occupancies']} (sans droit correspondant : {$report['occupancies_without_journey_match']})");
        $this->line("  CA billets : {$report['revenue_tickets']} FCFA · référence droits : {$report['revenue_journeys_reference']}");

        if ($report['anomalies'] === []) {
            $this->info('  Aucune anomalie détectée.');
        } else {
            foreach ($report['anomalies'] as $anomaly) {
                $this->error("  Anomalie : {$anomaly}");
            }
        }
    }
}
