<?php

use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique()->after('id');
        });

        Trip::query()
            ->whereNull('code')
            ->orderBy('created_at')
            ->get()
            ->each(function (Trip $trip): void {
                $seed = Str::upper(substr(str_replace('-', '', $trip->id), -6));
                $datePart = Carbon::parse($trip->created_at ?? now())->format('ymd');

                DB::table('trips')
                    ->where('id', $trip->id)
                    ->update([
                        'code' => sprintf('TRP-%s-%s', $datePart, $seed),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
