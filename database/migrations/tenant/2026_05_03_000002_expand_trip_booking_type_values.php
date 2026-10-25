<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE trips MODIFY booking_type ENUM('seat_assignment', 'bulk', 'semi_intelligent') NOT NULL DEFAULT 'seat_assignment'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE trips ALTER COLUMN booking_type TYPE VARCHAR(32)');
            DB::statement("ALTER TABLE trips ALTER COLUMN booking_type SET DEFAULT 'seat_assignment'");

            return;
        }

        // SQLite stores Laravel enum columns as text in this project, so no change is needed.
    }

    public function down(): void
    {
        DB::table('trips')
            ->where('booking_type', 'semi_intelligent')
            ->update(['booking_type' => 'seat_assignment']);

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE trips MODIFY booking_type ENUM('seat_assignment', 'bulk') NOT NULL DEFAULT 'seat_assignment'");
        }
    }
};
