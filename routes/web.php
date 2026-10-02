<?php

use App\Domains\Identity\Http\Controllers\LoginController;
use App\Domains\Identity\Http\Controllers\WorkspaceController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
 * Central routes: only answer on the platform's own domains
 * (clinicflow.co.za, app., accounts., localhost). Provider addresses
 * are handled in routes/tenant.php. Route names are registered on the
 * first central domain; the others get a numbered prefix.
 */

/** @var list<string> $centralDomains */
$centralDomains = config('tenancy.central_domains', []);

foreach ($centralDomains as $index => $domain) {
    Route::domain($domain)->name($index === 0 ? '' : "central{$index}.")->group(function (): void {
        Route::get('/', HomeController::class)->name('home');

        Route::middleware('guest')->group(function (): void {
            Route::get('/login', [LoginController::class, 'create'])->name('login');
            Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
            Route::get('/login/verify', [LoginController::class, 'verifyForm'])->name('login.verify');
            Route::post('/login/verify', [LoginController::class, 'verify'])->middleware('throttle:login-code')->name('login.verify.store');
        });

        Route::middleware('auth')->group(function (): void {
            Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces');
            Route::post('/workspaces/{provider}/open', [WorkspaceController::class, 'open'])->name('workspaces.open');
            Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        });
    });
}
