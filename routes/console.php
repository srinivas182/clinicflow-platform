<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks. Run the scheduler every minute on the server:
 * * * * * * php /var/www/html/artisan schedule:run
 */
Schedule::command('subscriptions:enforce')->dailyAt('02:00')->onOneServer();
Schedule::command('subscriptions:invoice')->dailyAt('01:00')->onOneServer();
