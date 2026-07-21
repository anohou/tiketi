<?php

namespace Tests\Unit;

use App\Support\TenantDatabaseReconciler;
use PHPUnit\Framework\TestCase;

class TenantDatabaseReconcilerTest extends TestCase
{
    public function test_it_classifies_matched_missing_and_orphaned_tenant_databases(): void
    {
        $result = (new TenantDatabaseReconciler)->classify(
            ['tiketi_tenant_alpha', 'tiketi_tenant_beta'],
            ['tiketi_tenant_alpha', 'tiketi_tenant_orphan'],
        );

        $this->assertSame([
            'matched' => ['tiketi_tenant_alpha'],
            'missing' => ['tiketi_tenant_beta'],
            'orphaned' => ['tiketi_tenant_orphan'],
        ], $result);
    }

    public function test_it_deduplicates_and_sorts_database_names(): void
    {
        $result = (new TenantDatabaseReconciler)->classify(
            ['tiketi_tenant_beta', 'tiketi_tenant_alpha', 'tiketi_tenant_alpha'],
            ['tiketi_tenant_beta', 'tiketi_tenant_alpha', 'tiketi_tenant_beta'],
        );

        $this->assertSame([
            'matched' => ['tiketi_tenant_alpha', 'tiketi_tenant_beta'],
            'missing' => [],
            'orphaned' => [],
        ], $result);
    }
}
