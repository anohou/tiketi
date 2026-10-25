<?php

namespace Tests\Integration;

use App\Support\TenantBaselineInspector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantBaselineInspectorPostgresTest extends TestCase
{
    private ConnectionInterface $connection;

    private TenantBaselineInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_BASELINE_POSTGRES_TEST') !== '1') {
            $this->markTestSkipped('Set TENANT_BASELINE_POSTGRES_TEST=1 to run PostgreSQL baseline integration tests.');
        }

        $this->connection = DB::connection('pgsql');
        $this->inspector = app(TenantBaselineInspector::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->transactionLevel() > 0) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function compatible_legacy_schema_is_adopted_without_touching_business_data(): void
    {
        $this->replaceHistoryWithLegacyMarkers();
        $this->connection->table('cache')->updateOrInsert(
            ['key' => 'baseline-adoption-integration-sentinel'],
            ['value' => 'must-survive', 'expiration' => 2147483647],
        );

        $this->assertSame('legacy', $this->inspector->inspect($this->connection)['state']);

        $result = $this->inspector->adopt($this->connection);

        $this->assertSame('adopted', $result['state']);
        $this->assertSame(42, $result['baseline_recorded']);
        $this->assertSame('must-survive', $this->connection->table('cache')
            ->where('key', 'baseline-adoption-integration-sentinel')->value('value'));
        $this->assertSame(1, $this->connection->table('migrations')
            ->where('migration', 'legacy_migration_preserved')->count());
    }

    #[Test]
    public function schema_drift_blocks_adoption_without_writing_migration_history(): void
    {
        $this->replaceHistoryWithLegacyMarkers();
        $this->connection->statement('DROP INDEX app.tickets_cancelled_by_index');
        $before = $this->connection->table('migrations')->count();

        $this->assertSame('incompatible', $this->inspector->inspect($this->connection)['state']);

        try {
            $this->inspector->adopt($this->connection);
            $this->fail('Schema drift must block baseline adoption.');
        } catch (\RuntimeException) {
            $this->assertSame($before, $this->connection->table('migrations')->count());
        }
    }

    private function replaceHistoryWithLegacyMarkers(): void
    {
        $this->connection->table('migrations')->truncate();
        $this->connection->table('migrations')->insert([
            ['migration' => '2025_08_08_191607_create_stations_table', 'batch' => 1],
            ['migration' => '2026_07_24_090000_create_authorized_devices_table', 'batch' => 2],
            ['migration' => '2026_08_10_000001_drop_round_trip_fares_table', 'batch' => 3],
            ['migration' => 'legacy_migration_preserved', 'batch' => 4],
        ]);
    }
}
