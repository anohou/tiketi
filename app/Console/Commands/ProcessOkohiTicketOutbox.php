<?php

namespace App\Console\Commands;

use App\Models\OkohiTicketOutbox;
use App\Models\Tenant;
use App\Services\OkohiTicketPublisher;
use Illuminate\Console\Command;

class ProcessOkohiTicketOutbox extends Command
{
    protected $signature = 'okohi:tickets-publish
        {--tenant= : Traiter un seul tenant (sinon tous)}
        {--limit= : Nombre maximal d’entrées à traiter (défaut : chunk configuré)}';

    protected $description = 'Publie les billets en attente vers le portefeuille Okohi (outbox avec reprises).';

    public function handle(OkohiTicketPublisher $publisher): int
    {
        $tenantOption = $this->option('tenant');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $tenants = $tenantOption
            ? Tenant::where('id', $tenantOption)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error('Aucun tenant trouvé.');

            return self::FAILURE;
        }

        $totalDelivered = 0;
        $totalFailed = 0;
        $totalPending = 0;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            $query = OkohiTicketOutbox::where('status', OkohiTicketOutbox::STATUS_PENDING)
                ->where('next_attempt_at', '<=', now())
                ->orderBy('created_at');

            $batch = ($limit ?? (int) config('transport.okohi.outbox_chunk_size', 25));

            $entries = (clone $query)->limit($batch)->get();

            foreach ($entries as $outbox) {
                $ok = $publisher->deliver($outbox);
                $ok ? $totalDelivered++ : $totalFailed++;
            }

            $totalPending += OkohiTicketOutbox::where('status', OkohiTicketOutbox::STATUS_PENDING)
                ->where('next_attempt_at', '<=', now())
                ->count();

            tenancy()->end();
        }

        $this->info("Publication Okohi : livrés {$totalDelivered} · échecs {$totalFailed} · en attente {$totalPending}");

        return self::SUCCESS;
    }
}
