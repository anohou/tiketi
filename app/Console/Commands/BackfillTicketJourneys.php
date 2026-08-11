<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketJourney;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillTicketJourneys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:backfill-journeys {--dry-run : Affiche ce qui serait fait sans écrire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée un droit de voyage aller (ticket_journey outbound) pour chaque billet existant, et complète les public_token manquants.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $failed = false;
        $dryRun = (bool) $this->option('dry-run');

        if (class_exists(Tenant::class) && Schema::hasTable('tenants') && Tenant::count() > 0) {
            foreach (Tenant::all() as $tenant) {
                $this->info("Backfill des droits de voyage pour le tenant : {$tenant->id}");

                tenancy()->initialize($tenant);

                try {
                    $this->backfillTenant($dryRun);
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("Échec du backfill pour le tenant {$tenant->id} : ".$e->getMessage());
                } finally {
                    tenancy()->end();
                }
            }
        } else {
            $this->info('Backfill en contexte local (single-tenant).');

            try {
                $this->backfillTenant($dryRun);
            } catch (\Throwable $e) {
                $failed = true;
                $this->error('Échec du backfill : '.$e->getMessage());
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function backfillTenant(bool $dryRun): void
    {
        $tickets = Ticket::with('outboundJourney')->get();

        $createdJourneys = 0;
        $filledTokens = 0;
        $skipped = 0;

        foreach ($tickets as $ticket) {
            // 1. Compléter le jeton public manquant.
            if (empty($ticket->public_token)) {
                if (! $dryRun) {
                    $ticket->forceFill(['public_token' => Ticket::generatePublicToken()])->save();
                }
                $filledTokens++;
            }

            // 2. Créer le droit aller manquant.
            if ($ticket->outboundJourney) {
                $skipped++;

                continue;
            }

            if (! $dryRun) {
                $status = $this->deriveStatus($ticket);

                TicketJourney::create([
                    'ticket_id' => $ticket->id,
                    'direction' => TicketJourney::DIRECTION_OUTBOUND,
                    'from_station_id' => $ticket->from_station_id,
                    'to_station_id' => $ticket->to_station_id,
                    'selection_mode' => TicketJourney::SELECTION_FIXED_TRIP,
                    'trip_id' => $ticket->trip_id,
                    'vehicle_id' => $ticket->vehicle_id,
                    'seat_number' => $ticket->seat_number,
                    'seat_assignment_status' => $ticket->seat_number !== null
                        ? TicketJourney::SEAT_CONFIRMED
                        : TicketJourney::SEAT_UNASSIGNED,
                    'status' => $status,
                    'valid_from' => $ticket->created_at,
                    'valid_until' => $ticket->return_valid_until,
                    'boarded_at' => $ticket->boarded_at,
                    'boarded_by' => $ticket->boarded_by,
                    'settings' => [
                        'backfilled' => true,
                        'backfilled_at' => now()->toDateTimeString(),
                    ],
                ]);
            }

            $createdJourneys++;
        }

        $this->line("  droits créés : {$createdJourneys} · jetons complétés : {$filledTokens} · déjà présents : {$skipped}"
            .($dryRun ? ' (dry-run)' : ''));
    }

    /**
     * Statut initial du droit dérivé de l'état du billet.
     */
    protected function deriveStatus(Ticket $ticket): string
    {
        if ($ticket->status === 'cancelled') {
            return TicketJourney::STATUS_CANCELLED;
        }

        if ($ticket->boarded_at !== null) {
            return TicketJourney::STATUS_BOARDED;
        }

        if ($ticket->trip_id !== null) {
            return TicketJourney::STATUS_ASSIGNED;
        }

        return TicketJourney::STATUS_PENDING;
    }
}
