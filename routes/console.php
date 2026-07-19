<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('trips:replicate')->dailyAt('00:00');
Schedule::command('offline-actions:purge')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('okohi:cleanup-expired')->everyMinute()->withoutOverlapping();
