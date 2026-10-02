<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks. Run the scheduler every minute on the server:
 * * * * * * php /var/www/html/artisan schedule:run
 */
Schedule::command('subscriptions:enforce')->dailyAt('02:00')->onOneServer();
