<?php

use App\Domains\Identity\Http\Controllers\LoginController;
use App\Domains\Identity\Http\Controllers\WorkspaceController;
use App\Domains\Platform\Http\Controllers\Admin\PackageAdminController;
use App\Domains\Platform\Http\Controllers\Admin\ProviderAdminController;
use App\Domains\Platform\Http\Controllers\PricingController;
use App\Domains\Platform\Http\Controllers\SignupController;
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
        Route::get('/pricing', PricingController::class)->name('pricing');

        Route::get('/start', [SignupController::class, 'create'])->name('signup');
        Route::post('/start', [SignupController::class, 'store'])->middleware('throttle:10,1')->name('signup.store');
        Route::get('/start/suggest', [SignupController::class, 'suggest'])->name('signup.suggest');
        Route::get('/start/done/{provider}', [SignupController::class, 'done'])->name('signup.done');

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

        Route::middleware(['auth', 'platform.admin'])->prefix('admin')->name('admin.')->group(function (): void {
            Route::get('/providers', [ProviderAdminController::class, 'index'])->name('providers.index');
            Route::get('/providers/{provider}', [ProviderAdminController::class, 'show'])->name('providers.show');
            Route::post('/providers/{provider}/approve', [ProviderAdminController::class, 'approve'])->name('providers.approve');
            Route::post('/verification-checks/{check}', [ProviderAdminController::class, 'review'])->name('checks.review');
            Route::get('/packages', [PackageAdminController::class, 'index'])->name('packages.index');
            Route::put('/packages/{package}', [PackageAdminController::class, 'update'])->name('packages.update');
        });
    });
}
