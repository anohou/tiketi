<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class TenantMigrationPretendPreflightTest extends TestCase
{
    public function test_integrity_checks_allow_schema_created_by_earlier_pending_migrations_in_pretend_mode(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_09_030001_add_integrity_constraints.php');
        $method = new ReflectionMethod($migration, 'safeForeign');
        $pretending = false;

        DB::connection()->pretend(function () use ($method, $migration, &$pretending) {
            $pretending = DB::connection()->pretending();
            $method->invoke(
                $migration,
                'departure_schedules',
                'station_id',
                'stations',
                'id',
                'fk_schedule_station',
            );
        });

        $this->assertTrue($pretending);
    }

    public function test_integrity_checks_remain_strict_during_a_real_migration(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_09_030001_add_integrity_constraints.php');
        $method = new ReflectionMethod($migration, 'safeForeign');

        $this->expectException(RuntimeException::class);
        $method->invoke(
            $migration,
            'departure_schedules',
            'station_id',
            'stations',
            'id',
            'fk_schedule_station',
        );
    }

    public function test_foreign_key_policy_checks_allow_pending_schema_in_pretend_mode(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_09_030003_correct_foreign_key_policies.php');
        $method = new ReflectionMethod($migration, 'assertColumn');

        DB::connection()->pretend(fn () => $method->invoke(
            $migration,
            'departure_schedules',
            'station_id',
            'stations',
            'fk3_departure_schedules_station_id',
        ));

        $this->addToAssertionCount(1);
    }
}
