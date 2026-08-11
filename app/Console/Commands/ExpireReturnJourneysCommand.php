<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ExpireReturnJourneys;
use Illuminate\Console\Command;

class ExpireReturnJourneysCommand extends Command
{
    protected $signature = 'returns:expire {--tenant= : Traiter un seul tenant (sinon tous)}';

    protected $description = 'Expire les retours arrivés à leur date limite (libère sièges, historique, Okohi).';

    public function handle(ExpireReturnJourneys $service): int
    {
        $tenantOption = $this->option('tenant');

        $tenants = $tenantOption
            ? Tenant::where('id', $tenantOption)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error('Aucun tenant trouvé.');

            return self::FAILURE;
        }

        $total = 0;

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);
            $count = $service->expire();
            $total += $count;
            $this->info("Tenant {$tenant->id} : {$count} retour(s) expiré(s).");
            tenancy()->end();
        }

        $this->info("Total : {$total} retour(s) expiré(s).");

        return self::SUCCESS;
    }
}
