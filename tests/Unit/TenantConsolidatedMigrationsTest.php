<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenantConsolidatedMigrationsTest extends TestCase
{
    #[Test]
    public function every_tenant_table_has_exactly_one_creation_migration(): void
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/tenant/*.php');
        $tables = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            preg_match_all("/Schema::create\('([^']+)'/", $contents, $matches);

            $this->assertCount(1, $matches[1], basename($file).' must create exactly one table.');
            $tables[] = $matches[1][0];
        }

        $this->assertCount(42, $files);
        $this->assertCount(42, array_unique($tables), 'Every table must have one migration only.');
    }
}
