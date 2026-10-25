<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepairMissingStationsTableMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('stations');
        Schema::dropIfExists('destinations');

        parent::tearDown();
    }

    public function test_it_recreates_the_current_stations_schema_when_the_historical_table_is_missing(): void
    {
        $this->createDestinationsTable();

        $migration = require database_path('migrations/tenant/2026_08_09_025001_repair_missing_stations_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('stations'));
        $this->assertTrue(Schema::hasColumns('stations', [
            'id',
            'name',
            'destination_id',
            'code',
            'city',
            'address',
            'latitude',
            'longitude',
            'phone',
            'active',
            'settings',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_it_leaves_an_existing_stations_table_and_its_data_untouched(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
        });
        DB::table('stations')->insert(['id' => 'station-1', 'name' => 'Existing station']);

        $migration = require database_path('migrations/tenant/2026_08_09_025001_repair_missing_stations_table.php');
        $migration->up();

        $this->assertSame('Existing station', DB::table('stations')->where('id', 'station-1')->value('name'));
    }

    private function createDestinationsTable(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });
    }
}
