<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $seen = [];

        DB::table('crew_members')
            ->select(['id', 'phone'])
            ->whereNotNull('phone')
            ->orderBy('id')
            ->each(function ($member) use (&$seen): void {
                $phone = PhoneNumber::normalize($member->phone);

                if ($phone === null) {
                    throw new RuntimeException("Numéro équipage invalide pour {$member->id}.");
                }

                if (isset($seen[$phone])) {
                    throw new RuntimeException(
                        "Numéro équipage dupliqué {$phone} pour {$seen[$phone]} et {$member->id}."
                    );
                }

                $seen[$phone] = $member->id;
                DB::table('crew_members')->where('id', $member->id)->update(['phone' => $phone]);
            });

        Schema::table('crew_members', function (Blueprint $table) {
            $table->unique('phone', 'crew_members_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropUnique('crew_members_phone_unique');
        });
    }
};
