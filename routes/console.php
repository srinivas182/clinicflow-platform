<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks. Run the scheduler every minute on the server:
 * * * * * * php /var/www/html/artisan schedule:run
 */
Schedule::command('subscriptions:enforce')->dailyAt('02:00')->onOneServer();
Schedule::command('subscriptions:invoice')->dailyAt('01:00')->onOneServer();
Schedule::command('subscriptions:collect')->dailyAt('06:00')->onOneServer();
Schedule::command('claims:remittances')->hourly()->onOneServer();
Schedule::command('pharmacy:return-uncollected')->dailyAt('23:30')->onOneServer();
Schedule::command('wallet:auto-topup')->everyFifteenMinutes()->onOneServer();
Schedule::command('telemedicine:tick')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('lab:release-tick')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('care:tick')->everyTenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('care:tick --recalls')->dailyAt('09:00')->onOneServer();
Schedule::command('accounting:export daily')->dailyAt('02:30')->onOneServer();
Schedule::command('accounting:export hourly')->hourly()->onOneServer();
Schedule::command('calendar:busy')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('pharmacy:publish-stock')->hourly()->onOneServer();
Schedule::command('feedback:request')->dailyAt('10:00')->onOneServer();
Schedule::command('packages:expire')->dailyAt('01:00')->onOneServer();
Schedule::command('status:check')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('locums:remind')->hourly()->onOneServer();
Schedule::command('webhooks:deliver')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('scribe:purge')->dailyAt('02:30')->onOneServer();
Schedule::command('reports:send')->dailyAt('07:00')->onOneServer();
Schedule::command('queue:alert')->hourly()->onOneServer();
Schedule::command('data:prune')->dailyAt('03:15')->onOneServer();
