<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\HandoffController;
use App\Domains\Patients\Http\Controllers\PatientController;
use App\Http\Controllers\Provider\ProviderHomeController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
 * Provider routes: answer on a provider's subdomain or verified custom
 * domain, inside that provider's own database.
 */
Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    Route::get('/auth/handoff/{token}', HandoffController::class)->name('provider.handoff');

    Route::middleware(['auth', 'workspace'])->group(function (): void {
        Route::get('/', ProviderHomeController::class)->name('provider.home');
        Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
        Route::get('/patients/register', [PatientController::class, 'create'])->name('patients.create');
        Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    });
});
