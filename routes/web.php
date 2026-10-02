<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
 * Central routes: only answer on the platform's own domains
 * (clinicflow.co.za, app., admin., localhost). Provider addresses
 * are handled in routes/tenant.php.
 */

/** @var list<string> $centralDomains */
$centralDomains = config('tenancy.central_domains', []);

foreach ($centralDomains as $index => $domain) {
    Route::domain($domain)->group(function () use ($index): void {
        Route::get('/', HomeController::class)->name($index === 0 ? 'home' : "home.{$index}");
    });
}
