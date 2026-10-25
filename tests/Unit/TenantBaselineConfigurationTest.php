<?php

namespace Tests\Unit;

use App\Support\TenantBaselineInspector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantBaselineConfigurationTest extends TestCase
{
    #[Test]
    public function baseline_is_pinned_to_the_consolidated_migrations_only(): void
    {
        $inspector = app(TenantBaselineInspector::class);
        $migrations = $inspector->baselineMigrationNames();

        $this->assertCount(42, $migrations);
        $this->assertSame('0001_01_01_000001_create_authorized_devices_table', $migrations[0]);
        $this->assertSame('0001_01_01_000042_create_vehicle_crew_assignments_table', $migrations[41]);
        $this->assertNotSame('', config('tenant-baseline.schema_fingerprint'));
        $this->assertCount(3, config('tenant-baseline.legacy_markers'));
    }

    #[Test]
    public function deployments_check_then_adopt_the_baseline_before_migrating(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $contents = file_get_contents(base_path("deployment/environments/{$environment}/config/config.yml"));

            $this->assertStringContainsString(
                'tenant_preflight_command: "php artisan tenants:baseline:check --no-interaction"',
                $contents,
            );
            $this->assertStringContainsString(
                'tenant_command: "php artisan tenants:baseline:adopt --force --no-interaction && php artisan tenants:migrate --force --no-interaction"',
                $contents,
            );
        }
    }

    #[Test]
    public function equivalent_postgresql_check_renderings_are_normalized_identically(): void
    {
        $inspector = app(TenantBaselineInspector::class);
        $method = new \ReflectionMethod($inspector, 'normalizeCheck');

        $consolidated = "((booking_type)::text = ANY ((ARRAY['seat_assignment'::character varying, 'bulk'::character varying])::text[]))";
        $historical = "((booking_type)::text = ANY (ARRAY[('seat_assignment'::character varying)::text, ('bulk'::character varying)::text]))";

        $this->assertSame(
            $method->invoke($inspector, $consolidated),
            $method->invoke($inspector, $historical),
        );
    }
}
