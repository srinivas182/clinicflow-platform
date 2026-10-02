<?php

declare(strict_types=1);

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
    Route::get('/', ProviderHomeController::class)->name('provider.home');
});
