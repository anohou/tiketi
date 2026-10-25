<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stations')) {
            return;
        }

        // Some legacy tenant databases recorded the original stations migration
        // without retaining the table. Recreate its current schema before the
        // new scheduling foreign keys are applied.
        Schema::create('stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->index();
            $table->foreignUuid('destination_id')
                ->nullable()
                ->constrained('destinations')
                ->nullOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable()->comment('Latitude GPS');
            $table->decimal('longitude', 10, 7)->nullable()->comment('Longitude GPS');
            $table->string('phone')->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: this migration repairs a core table and
        // rollback must never discard stations created after the repair.
    }
};
