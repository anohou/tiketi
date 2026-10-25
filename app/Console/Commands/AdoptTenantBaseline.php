<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantBaselineInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

final class AdoptTenantBaseline extends Command
{
    protected $signature = 'tenants:baseline:adopt
                            {--tenant=* : Limit adoption to specific tenant IDs}
                            {--force : Required in production}';

    protected $description = 'Adopt the consolidated tenant baseline after a strict schema compatibility check';

    public function handle(TenantBaselineInspector $inspector): int
    {
        if (App::environment('production') && ! $this->option('force')) {
            $this->error('The --force option is required in production.');

            return self::FAILURE;
        }

        $tenants = $this->tenants();
        $preflight = [];

        // Check every selected tenant before writing to any tenant database.
        foreach ($tenants as $tenant) {
            try {
                tenancy()->initialize($tenant);
                $preflight[(string) $tenant->getTenantKey()] = $inspector->inspect(DB::connection('tenant'));
            } catch (\Throwable $exception) {
                $preflight[(string) $tenant->getTenantKey()] = [
                    'state' => 'incompatible',
                    'errors' => [$exception->getMessage()],
                ];
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $incompatible = array_filter(
            $preflight,
            static fn (array $inspection): bool => $inspection['state'] === 'incompatible',
        );
        if ($incompatible !== []) {
            foreach ($incompatible as $tenantId => $inspection) {
                $this->error("Tenant [{$tenantId}] is incompatible: ".implode(' ', $inspection['errors'] ?? []));
            }
            $this->warn('No tenant baseline records were written.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->getTenantKey();
            $state = $preflight[$tenantId]['state'];

            if ($state === 'empty') {
                $this->line("Tenant [{$tenantId}] is empty; normal consolidated migrations will initialize it.");

                continue;
            }
            if ($state === 'adopted') {
                $this->line("Tenant [{$tenantId}] already adopted the consolidated baseline.");

                continue;
            }

            try {
                tenancy()->initialize($tenant);
                $result = $inspector->adopt(DB::connection('tenant'));
                $this->info("Tenant [{$tenantId}] adopted {$result['baseline_recorded']}/{$result['baseline_total']} baseline migrations.");
            } catch (\Throwable $exception) {
                $this->error("Tenant [{$tenantId}] adoption failed: {$exception->getMessage()}");

                return self::FAILURE;
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Tenant> */
    private function tenants(): Collection
    {
        $requested = array_values(array_filter(array_map('strval', (array) $this->option('tenant'))));
        $query = Tenant::query()->orderBy('id');

        if ($requested !== []) {
            $query->whereIn('id', $requested);
        }

        $tenants = $query->get();
        if ($requested !== [] && $tenants->count() !== count(array_unique($requested))) {
            throw new \InvalidArgumentException('One or more requested tenant IDs do not exist.');
        }

        return $tenants;
    }
}
