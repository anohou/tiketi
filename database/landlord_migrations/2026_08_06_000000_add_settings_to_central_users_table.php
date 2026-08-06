<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `settings` JSON column to the CENTRAL (landlord) users table.
 *
 * The tenant users table has had `settings` since inception, but the central
 * users table never did. LocaleController::update() persists the chosen
 * locale into $user->settings — writing that column on the central table
 * used to throw SQLSTATE[42703] (column "settings" does not exist) and
 * return a 500 on POST /locale for logged-in platform admins on the
 * central domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'settings')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('settings')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'settings')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('settings');
            });
        }
    }
};
