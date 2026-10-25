<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantBaselineInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CheckTenantBaseline extends Command
{
    protected $signature = 'tenants:baseline:check
                            {--tenant=* : Limit the check to specific tenant IDs}
                            {--json : Emit a machine-readable report}';

    protected $description = 'Read-only compatibility check for the consolidated tenant migration baseline';

    public function handle(TenantBaselineInspector $inspector): int
    {
        $reports = [];
        $failed = false;

        foreach ($this->tenants() as $tenant) {
            try {
                tenancy()->initialize($tenant);
                $inspection = $inspector->inspect(DB::connection('tenant'));
                $reports[] = ['tenant' => (string) $tenant->getTenantKey()] + $inspection;
                $failed = $failed || $inspection['state'] === 'incompatible';
            } catch (\Throwable $exception) {
                $failed = true;
                $reports[] = [
                    'tenant' => (string) $tenant->getTenantKey(),
                    'state' => 'incompatible',
                    'errors' => [$exception->getMessage()],
                ];
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->renderReports($reports);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return iterable<int, Tenant> */
    private function tenants(): iterable
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

    /** @param list<array<string, mixed>> $reports */
    private function renderReports(array $reports): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(
            ['Tenant', 'State', 'Baseline', 'Fingerprint', 'Errors'],
            array_map(static fn (array $report): array => [
                $report['tenant'],
                $report['state'],
                isset($report['baseline_recorded'], $report['baseline_total'])
                    ? "{$report['baseline_recorded']}/{$report['baseline_total']}"
                    : '-',
                isset($report['fingerprint']) && $report['fingerprint'] !== ''
                    ? substr((string) $report['fingerprint'], 0, 12)
                    : '-',
                implode(' ', $report['errors'] ?? []),
            ], $reports),
        );
    }
}
