<?php

use App\Domains\Billing\Http\Controllers\GatewayWebhookController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * REST API v1 — used by the Flutter apps, partners and integrations.
 * Every business endpoint lives under /api/v1 and is documented in OpenAPI.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
});

/*
 * Platform (subscription) gateway webhooks.
 */
Route::post('webhooks/platform/{gateway}', [GatewayWebhookController::class, 'platform'])
    ->whereIn('gateway', ['payfast', 'paystack', 'peach', 'yoco'])
    ->name('webhooks.platform');
