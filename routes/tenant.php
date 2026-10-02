<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\HandoffController;
use App\Domains\Patients\Http\Controllers\PatientController;
use App\Domains\Scheduling\Http\Controllers\AppointmentController;
use App\Domains\Scheduling\Http\Controllers\RosterController;
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

    Route::middleware(['auth', 'workspace', 'provider.writable'])->group(function (): void {
        Route::get('/', ProviderHomeController::class)->name('provider.home');
        Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
        Route::get('/patients/register', [PatientController::class, 'create'])->name('patients.create');
        Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');

        Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');

        Route::get('/rosters', [RosterController::class, 'index'])->name('rosters.index');
        Route::post('/rosters', [RosterController::class, 'store'])->name('rosters.store');
        Route::post('/rooms', [RosterController::class, 'storeRoom'])->name('rooms.store');
    });
});
