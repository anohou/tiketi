<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PurgeExpiredOfflineActions extends Command
{
    protected $signature = 'offline-actions:purge';

    protected $description = 'Delete expired terminal crew offline action receipts.';

    public function handle(): int
    {
        $deleted = 0;

        if (class_exists(Tenant::class) && Schema::hasTable('tenants') && Tenant::query()->exists()) {
            foreach (Tenant::query()->cursor() as $tenant) {
                try {
                    tenancy()->initialize($tenant);
                    $deleted += $this->purgeCurrentDatabase();
                } catch (Throwable $exception) {
                    $this->error("Unable to purge offline actions for tenant {$tenant->id}: {$exception->getMessage()}");
                } finally {
                    tenancy()->end();
                }
            }
        } else {
            $deleted = $this->purgeCurrentDatabase();
        }

        $this->info("Purged {$deleted} expired offline action receipt(s).");

        return self::SUCCESS;
    }

    private function purgeCurrentDatabase(): int
    {
        if (! Schema::hasTable('crew_offline_actions')) {
            return 0;
        }

        return DB::table('crew_offline_actions')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
