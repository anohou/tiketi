<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('trips:materialize-schedules')
    ->dailyAt('00:05')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
// Rattrapage après le changement de journée opérationnelle (défaut 03:00) :
// traite les échecs transitoires avant l'ouverture des gares.
Schedule::command('trips:materialize-schedules')
    ->dailyAt('03:35')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
Schedule::command('offline-actions:purge')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('tickets:reconcile')->dailyAt('04:15')->withoutOverlapping();
Schedule::command('returns:expire')->dailyAt('03:45')->withoutOverlapping();
Schedule::command('okohi:tickets-publish')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('okohi:cleanup-expired')->everyMinute()->withoutOverlapping();
